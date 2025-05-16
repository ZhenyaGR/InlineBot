<?php

namespace ZhenyaGR\TGZ;

use Throwable, Exception;

trait ErrorHandler
{

    private mixed $user_error_handler_or_ids = null;

    private array $paths_to_filter = [];

    private bool $short_trace = false;

    private bool $send_error_in_vk = true;

    private bool $is_exis_exiting = false;

    /**
     *
     * Устанавливает обработчик ошибок и исключений, перенаправляя их для логирования и вывода.
     *
     * @param int|array<int>|callable $ids VK ID пользователя, массив ID или функция-обработчик.
     *
     * @return TGZ Возвращает текущий экземпляр для цепочки вызовов.
     */
    public function setUserLogError(callable|array|int $ids): TGZ
    {
        $this->user_error_handler_or_ids = is_numeric($ids) ? [$ids] : $ids;

        ini_set('error_reporting', E_ALL);
        ini_set('display_errors', 1);
        //при включении log_errors по умолчанию вывод идет в stderr, что приводит к дубрированию ошибок
        ini_set('log_errors', 0);
        // Перенаправляет ошибки в файл, а не в stderr
        // ini_set('error_log', '/path/to/php-error.log');
        ini_set('display_startup_errors', 1);

        set_error_handler([$this, 'userErrorHandler']); //Для пользовательских ошибок и всех нефатальных
        set_exception_handler([$this, 'exceptionHandler']); //Для необработанных исключений
        //Для обнаружения фатальных ошибок, из-за которых не успевают сработать обычные обработчики
        register_shutdown_function(fn() => $this->checkForFatalError());
        return $this;
    }

    /**
     * Устанавливает пути к файлам, которые необходимо убрать из трейса
     * @param string|array $pathes Путь или массив путей
     * @return void
     */
    public function setTracePathFilter(string|array $pathes): void
    {
        $pathes = is_string($pathes) ? [$pathes] : $pathes;
        $this->paths_to_filter = array_map(static fn($path) => str_replace('\\', '/', $path), $pathes);
    }

    /**
     *
     * Оставляет в трейсе только пользовательские файлы, без файлов библиотеки
     *
     * @param bool $enable - вкл/выкл отображение короткого трейса
     *
     * @return TGZ Возвращает текущий экземпляр для цепочки вызовов.
     */
    public function shortTrace(bool $enable = true): TGZ
    {
        $this->short_trace = $enable;
        return $this;
    }

    /**
     * Публичный, потому что исключения могут вызываться и обрабатываться за пределами текущего класса
     */
    public function exceptionHandler(
        Throwable $exception,
        int $set_type = E_ERROR,
        bool $is_artificial_trace = false
    ): void {
        $message = $this->normalizeMessage($exception->getMessage());
        $message = $this->filterPaths($message);
        $message = $this->coloredLog($message, 'RED');
        $file = $this->normalizeMessage($exception->getFile());
        $line = $this->normalizeMessage($exception->getLine());
        $code = $exception->getCode();

        $trace = $exception->getTrace();
        if (empty($trace)) {
            $trace = [
                ['file' => $file, 'line' => $line, 'function' => 'Unknown function']
            ];
        }
        $trace = $this->buildNewTrace($trace, $file, $line, $is_artificial_trace);

        $this->userErrorHandler(
            $set_type,
            $message . "\n\n\n$trace",
            $file,
            $line,
            $code,
            $exception,
            $is_artificial_trace
        );
    }

    /**
     *
     * Публичный, потому что исключения могут вызываться и обрабатываться за пределами текущего класса
     *
     * @return true
     */
    public function userErrorHandler(
        int $type,
        string $message,
        string $file,
        int $line,
        ?int $code = null,
        ?Throwable $exception = null,
        bool $is_artificial_trace = false
    ): bool {
        // если ошибка попадает в отчет (при использовании оператора "@" error_reporting() вернет 0)
        $web_msg = null;
        if (error_reporting() & $type) {
            $error_type_without_trace = [
                E_WARNING,   // Системные предупреждения
                E_NOTICE,    // Системные уведомления
                E_USER_WARNING,   // Пользовательские предупреждения
                E_USER_NOTICE,    // Пользовательские уведомления
                E_USER_ERROR,     // Пользовательские ошибки
                E_DEPRECATED,     // Устаревшие функции
                E_USER_DEPRECATED // Пользовательские устаревшие функции
            ];

            if (!$is_artificial_trace && in_array($type, $error_type_without_trace, true)) {
                //Создаем исскуственный трейс для ошибки не содержащей полного трейса
                $exception = new Exception($message);
                // Передаем оригинальный тип ошибки и получаем полный трейс
                $this->exceptionHandler($exception, $type, true);
                return true;
            }

            [$error_level, $error_type] = $this->defaultErrorLevelMap()[$type] ?? ['NOTICE', 'NOTICE'];
            $error_level_str = $this->formatErrorLevel($error_level);

            $color_msg = "$error_level_str$message";
            $color_msg = str_replace("\n\n", "\n", $color_msg);
            $clear_msg = preg_replace('/\033\[[0-9;]*m/', '', $color_msg); //Очистка от цвета

            if ($exception) {
                $is_regular_console = (PHP_SAPI === 'cli') &&
                    defined('STDOUT') &&
                    ($meta = stream_get_meta_data(STDOUT)) &&
                    $meta['wrapper_type'] === 'PHP' &&
                    $meta['stream_type'] === 'STDIO';

                if (!$is_regular_console) {
                }

                //Выводить цветное только если скрипт запущен из консоли и вывод не перенаправляется в файл
                //При использовании nohup, crontab и т.д. вывод будет не цветным
                print $is_regular_console ? $color_msg : $web_msg;
            }

            $this->dispatchErrorMessage($error_type, $clear_msg, $code, $exception);

            if (in_array($error_level, ['ERROR', 'CRITICAL'])) {
                $this->is_exis_exiting = true; //чтобы не сработала register_shutdown_function()
                exit();
            }
        }
        return true;
    }

    private function checkForFatalError(): void
    {
        if ($this->is_exis_exiting) {
            return;
        }
        if ($error = error_get_last()) {
            $type = $error['type'];
            if ($type & E_ALL) {
                //запускаем обработчик ошибок
                $this->userErrorHandler($type, $error['message'], $error['file'], $error['line']);
            }
        }
    }

    /**
     * @return string[][]
     *
     * @psalm-return array{1: list{'CRITICAL', 'E_ERROR'}, 2: list{'WARNING', 'E_WARNING'}, 4: list{'ERROR', 'E_PARSE'}, 8: list{'NOTICE', 'E_NOTICE'}, 16: list{'CRITICAL', 'E_CORE_ERROR'}, 32: list{'WARNING', 'E_CORE_WARNING'}, 64: list{'CRITICAL', 'E_COMPILE_ERROR'}, 128: list{'WARNING', 'E_COMPILE_WARNING'}, 256: list{'ERROR', 'E_USER_ERROR'}, 512: list{'WARNING', 'E_USER_WARNING'}, 1024: list{'NOTICE', 'E_USER_NOTICE'}, 4096: list{'ERROR', 'E_RECOVERABLE_ERROR'}, 8192: list{'NOTICE', 'E_DEPRECATED'}, 16384: list{'NOTICE', 'E_USER_DEPRECATED'}}
     */
    private function defaultErrorLevelMap(): array
    {
        return [
            E_ERROR => ['CRITICAL', 'E_ERROR'],
            E_WARNING => ['WARNING', 'E_WARNING'],
            E_PARSE => ['ERROR', 'E_PARSE'],
            E_NOTICE => ['NOTICE', 'E_NOTICE'],
            E_CORE_ERROR => ['CRITICAL', 'E_CORE_ERROR'],
            E_CORE_WARNING => ['WARNING', 'E_CORE_WARNING'],
            E_COMPILE_ERROR => ['CRITICAL', 'E_COMPILE_ERROR'],
            E_COMPILE_WARNING => ['WARNING', 'E_COMPILE_WARNING'],
            E_USER_ERROR => ['ERROR', 'E_USER_ERROR'],
            E_USER_WARNING => ['WARNING', 'E_USER_WARNING'],
            E_USER_NOTICE => ['NOTICE', 'E_USER_NOTICE'],
            E_RECOVERABLE_ERROR => ['ERROR', 'E_RECOVERABLE_ERROR'],
            E_DEPRECATED => ['NOTICE', 'E_DEPRECATED'],
            E_USER_DEPRECATED => ['NOTICE', 'E_USER_DEPRECATED'],
        ];
    }

    private function formatErrorLevel(string $level): string
    {
        return match ($level) {
            'ERROR', 'CRITICAL' => $this->coloredLog('‼Fatal Error: ', 'RED'),
            'WARNING' => $this->coloredLog('⚠️Warning: ', 'YELLOW'),
            'NOTICE' => $this->coloredLog('⚠️Notice: ', 'BLUE'),
            default => $this->coloredLog('‼Unknown Error: ', 'RED'),
        };
    }

    private function coloredLog(string $text, string $color): string
    {
        $color_codes = [
            'RED' => "\033[31m",
            'GREEN' => "\033[32m",
            'YELLOW' => "\033[33m",
            'BLUE' => "\033[34m",
            'WHITE' => "\033[37m",
        ];
        $color_code = $color_codes[$color];
        return "$color_code$text\033[0m";
    }

    private function dispatchErrorMessage(
        string $type,
        string $message,
        ?int $code = null,
        ?Throwable $exception = null
    ): void {
        if (is_callable($this->user_error_handler_or_ids)) {
            call_user_func($this->user_error_handler_or_ids, $type, $message, $code, $exception);
        } else {
            if ($this->send_error_in_vk) {
                foreach ($this->user_error_handler_or_ids as $chatID) {
                    try {
                        //Ошибки не вызываются при недоставке юзеру, потому что у peer_ids другой формат ответа
                        $this->callAPI('sendMessage', [
                            'chat_id' => $chatID,
                            'text' => $message
                        ]);
                    } catch (Exception $e) {
                        $this->send_error_in_vk = false;
                        trigger_error('Не удалось отправить ошибку в ЛС: ' . $e->getMessage(), E_USER_WARNING);
                    }
                }
            }
        }
    }

    /**
     * @return false|string
     */
    protected function getCodeSnippet(string $file, int $line, int $padding = 0): string|false
    {
        static $files_cache = [];

        if (!isset($files_cache[$file]) && is_readable($file)) {
            $files_cache[$file] = file($file, FILE_IGNORE_NEW_LINES);
        }

        if (!isset($files_cache[$file])) {
            return false;
        }

        $lines = $files_cache[$file];
        $start = max(0, $line - $padding - 1);
        $end = min(count($lines), $line + $padding);
        $snippet = '';

        for ($i = $start; $i < $end; $i++) {
            $line_number = ($i + 1) . ': ';
            $line_number = $this->coloredLog($line_number, 'YELLOW');
            $code = $this->coloredLog(trim($lines[$i]), 'WHITE');
            $snippet .= $line_number . $code . PHP_EOL;
        }

        return $snippet;
    }

    private function normalizeMessage(string $message): string
    {
        // Шаг 1: Нормализуем запись Array (
        $message = preg_replace('/Array\s*\n?\s*\(/is', '[', $message);

        // Шаг 2: Заменяем закрывающие скобки на "]"
        $message = str_replace(')', ']', $message);

        // Шаг 3: Обрабатываем отступы
        $lines = explode("\n", $message);

        $result = [];


        foreach ($lines as $line) {
            // Определяем количество пробелов в начале строки
            $leadingSpaces = strspn($line, ' ');

            if ($leadingSpaces > 0) {
                // Базовое сокращение пробелов в половину
                $newIndent = floor($leadingSpaces / 2);

                // Если после пробелов идет '[', уменьшаем еще на 1
                if (isset($line[$leadingSpaces]) && $line[$leadingSpaces] === '[') {
                    $newIndent = max(0, $newIndent + 2);
                }

                // Формируем новую строку с уменьшенным отступом
                $content = substr($line, $leadingSpaces);
                $line = str_repeat(' ', $newIndent) . $content;
            }

            $result[] = rtrim($line); // Удаляем пробелы в конце строки
        }

        return trim(implode("\n", $result));
    }

    private function buildNewTrace(array $trace_data, string $file, int $line, bool $is_artificial_trace): string
    {
        $trace = '';

        //при отсутствии изначального трейса или это user_error()
        //трейс был создан искуственно
        if ($is_artificial_trace) {
            $first_trace = $trace_data[0] ?? null;
            if ($first_trace
                && !isset($first_trace['file'])
                && $first_trace['function'] === 'userErrorHandler'
                && $first_trace['class'] === 'ZhenyaGR\TGZ\TGZ') {
                //поледний трейс идет из SimpleVK класса, потому что он использует трейт ErrorHandler
                array_shift($trace_data); //удаляем userErrorHandler()
            }
        }

        //добавляем строку ошибки в трейс на 0-е место
        if (!$is_artificial_trace && isset($trace_data[0]) && ($trace_data[0]['file'] != $file || $trace_data[0]['line'] != $line)) {
            array_unshift($trace_data, ['file' => $file, 'line' => $line]);
        }

        foreach ($trace_data as $num => $data) {
            $trace .= $this->formatTraceLine($data, $num);
        }

        return $trace;
    }

    private function formatTraceLine(array $trace, int $num): string
    {
//        $type = $trace['type'] ?? '';
        $function = $trace['function'] ?? '{unknown function}';
        $class = $trace['class'] ?? '';
//        $class = str_replace(["DigitalStars\DataBase\\", "DigitalStars\SimpleVK\\"], "", $class);
//        $args = $trace['args'] ?? [];
        $trace_line = '';
        $file = $trace['file'] ?? 'unknown file';
        $line = $trace['line'] ?? '?';

        //[internal function]

        $code_snippet = $this->getCodeSnippet($file, (int)$line);
        $pattern = '#/(vendor|TGZ[^/]*/src)(/.*)#i';

        $formatted_file = $this->filterPaths($file);
        if (isset($trace['file']) && preg_match($pattern, $formatted_file, $matches)) {
            $formatted_file = ".." . $matches[0];
            $user_file_marker = '';
        } elseif (!isset($trace['file']) || !$code_snippet) {
            $user_file_marker = '';
        } else {
            $user_file_marker = '➡ ';
        }

        // Если короткий трейс выключен или это не файл библиотеки
        if (!$this->short_trace || !empty($user_file_marker)) {
            if (!isset($trace['file']) && !isset($trace['line'])) {
                $trace_line .= $this->coloredLog("$user_file_marker#$num ", 'GREEN')
                    . $this->coloredLog('[internal function]', 'BLUE') . "\n"
                    . $this->coloredLog("?:", 'YELLOW')
                    . $this->coloredLog(" $class->$function()", 'WHITE') . "\n\n";
            } else {
                $trace_line .= $this->coloredLog("$user_file_marker#$num ", 'GREEN')
                    . $this->coloredLog($formatted_file, 'BLUE')
                    . $this->coloredLog(":$line", 'YELLOW') . "\n"
                    . $this->coloredLog($code_snippet, 'WHITE') . "\n\n";
            }
        }

        return $trace_line;
    }

    private function filterPaths(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        foreach ($this->paths_to_filter as $filter) {
            $path = str_replace($filter, '..', $path);
        }
        return $path;
    }

    private function formatArray(array $array, int $indent = 0): string
    {
        $space = str_repeat(" ", $indent * 2); // символ " " (U+2007, Figure Space)
        $result = "Array (\n";

        foreach ($array as $key => $value) {
            if (is_string($value) && ($decoded = json_decode($value, true)) !== null) {
                // Если значение — JSON, декодируем его в массив
                $value = $decoded;
            }

            if (is_array($value)) {
                $result .= $space . "  [$key] => " . $this->formatArray($value, $indent + 1);
            } else {
                $result .= $space . "  [$key] => " . ($value ?: 'null') . "\n";
            }
        }
        return $result . $space . ")\n";
    }

    private function TGAPIErrorMSG($response, $params): string
    {
        $function_params['error_code'] = $response['error_code'];
        $function_params['description'] = $response['description'];
        $function_params['request_params'] = $params;
        return "Telegram API error:\n" . $this->formatArray($function_params);
    }
}