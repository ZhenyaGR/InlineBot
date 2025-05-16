<?php

namespace ZhenyaGR\TGZ;

use CURLFile;
use Exception;

final class TGZ
{
    use ErrorHandler;


    public string $apiUrl;
    public string $token;
    public array $update;
    public string $parseModeDefault = '';


    public static function create(string $token): self
    {
        return new self($token);
    }

    public function __construct(string $token)
    {
        $this->sendOK();

        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$token}/";

        $input = file_get_contents('php://input');
        $update = json_decode($input, true);
        $this->update = $update;
    }

    public function callAPI(string $method, ?array $params = []): array
    {
        $url = $this->apiUrl.$method;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $response;
        }

        throw new Exception($this->TGAPIErrorMSG($response, $params));

        return $response;
    }

    public function getWebhookUpdate(): array
    {
        return $this->update;
    }

    public function __call(string $method, array $args = []): array
    {
        $args = (empty($args)) ? $args : $args[0];

        return $this->callAPI($method, $args);
    }

    public function initVars(
        &$chat_id = null,
        &$user_id = null,
        &$text = null,
        &$type = null,
        &$callback_data = null,
        &$query_id = null,
        &$msg_id = null,
        &$is_bot = null,
        &$is_command = null,
    ): array {
        $update = $this->update;

        $this
            ->initUserID($user_id)
            ->initChatID($chat_id)
            ->initText($text)
            ->initMsgID($msg_id)
            ->initType($type);

        if (isset($update['message'])) {
            $is_bot = $update['message']['from']['is_bot'];
            $is_command = (isset($update['message']['entities'][0]['type'])
                && $update['message']['entities'][0]['type'] === 'bot_command');
            $callback_data = false;
            $query_id = false;

        } elseif (isset($update['callback_query'])) {
            $is_bot = $update['callback_query']['from']['is_bot'];
            $is_command = false;
            $callback_data = $update['callback_query']['data'];
            $query_id = $update['callback_query']['id'];

        } elseif (isset($update['inline_query'])) {
            $is_bot = $update['inline_query']['from']['is_bot'];
            $is_command = false;
            $callback_data = false;
            $query_id = $update['inline_query']['id'];

        }

        return $update;
    }

    public function initType(&$type): static
    {
        if (isset($this->update['message'])) {
            $type = (isset($this->update['message']['entities'][0]['type'])
                && $this->update['message']['entities'][0]['type']
                === 'bot_command') ? 'bot_command' : 'text';
        } elseif (isset($this->update['callback_query'])) {
            $type = 'callback_query';
        } elseif (isset($this->update['edited_message'])) {
            $type = 'edited_message';
        } elseif (isset($this->update['inline_query'])) {
            $type = 'inline_query';
        }

        return $this;
    }

    public function initMsgID(&$msg_id): static
    {
        $msg_id = $this->update['message']['message_id'] ??
            $this->update['edited_message']['message_id'] ??
            $this->update['callback_query']['message']['message_id'] ??
            $this->update['callback_query']['inline_message_id'] ??
            null;

        return $this;
    }

    public function initText(&$text): static
    {
        $text = $this->update['message']['text'] ??
            $this->update['message']['caption'] ??
            $this->update['edited_message']['text'] ??
            $this->update['edited_message']['caption'] ??
            $this->update['callback_query']['message']['text'] ??
            $this->update['callback_query']['message']['caption'] ??
            $this->update['inline_query']['query'] ??
            '';

        return $this;
    }

    public function initUserID(&$user_id): static
    {
        $user_id = $this->update['message']['from']['id'] ??
            $this->update['edited_message']['from']['id'] ??
            $this->update['callback_query']['from']['id'] ??
            $this->update['inline_query']['from']['id'] ??
            null;

        return $this;
    }

    public function initChatID(&$chat_id): static
    {
        $chat_id = $this->update['message']['chat']['id'] ??
            $this->update['edited_message']['chat']['id'] ??
            $this->update['callback_query']['message']['chat']['id'] ??
            null;

        return $this;
    }

    public function defaultParseMode(string $mode = ''): static
    {
        if ($mode !== 'HTML' && $mode !== 'Markdown' && $mode !== 'MarkdownV2'
            && $mode !== ''
        ) {
            $mode = '';
        }
        $this->parseModeDefault = $mode;

        return $this;
    }

    public function sendOK(): void
    {
        http_response_code(200);
        echo 'ok';
    }

    public function msg(string $text = ''): Message
    {
        return new Message($text, $this);
    }

    public function inline(string $type = ''): Inline
    {
        return new Inline($type, $this);
    }

    public function delMsg(array|int $msg_ids, int $chat_id = null): array
    {
        if ($chat_id === null) {
            $this->initChatID($chat_id);
        }

        $bool = is_array($msg_ids);
        $method = $bool ? 'deleteMessages' : 'deleteMessage';
        $param = $bool ? 'messages_id' : 'message_id';

        return $this->callAPI(
            $method, ['chat_id' => $chat_id, $param => $msg_ids],
        );
    }

    public function getFileID(string $url, int $chat_id,
        string $type = 'document',
    ): string {
        if (!in_array(
            $type,
            ['document', 'audio', 'photo', 'animation', 'video', 'video_note',
             'voice', 'sticker'],
        )
        ) {
            $type = 'document';
        }
        $params[$type] = new CURLFile($url);
        $params['chat_id'] = $chat_id;

        $method = 'send'.ucfirst($type);
        $result = $this->callAPI($method, $params);

        if ($type === 'photo') {
            // Берем последний элемент массива (наибольший по размеру вариант)
            return end($result['result']['photo'])['file_id'];
        }

        if ($type === 'audio') {
            return $result['result']['audio']['file_id'];
        }

        if ($type === 'video') {
            return $result['result']['video']['file_id'];
        }

        return $result['result']['document']['file_id'];
    }

    public function sendMessage(int $chatId, string $text): array
    {
        $params = [
            'chat_id' => $chatId,
            'text'    => $text,
        ];

        return $this->callAPI('sendMessage', $params);
    }

    /**
     * @return string[]
     *
     * @psalm-return array{text: string, callback_data: string}
     */
    public function buttonCallback(string $buttonText, string $buttonData,
    ): array {
        return [
            'text'          => $buttonText,
            "callback_data" => $buttonData,
        ];
    }

    /**
     * @return string[]
     *
     * @psalm-return array{text: string, url: string}
     */
    public function buttonUrl(string $buttonText, string $buttonUrl): array
    {
        return [
            'text' => $buttonText,
            "url"  => $buttonUrl,
        ];
    }

    /**
     * @return string[]
     *
     * @psalm-return array{text: string}
     */
    public function buttonText(string $buttonText): array
    {
        return [
            'text' => $buttonText,
        ];
    }

    public function answerCallbackQuery(string $callbackID, array $options = [],
    ): array {
        $params = array_merge([
            'callback_query_id' => $callbackID,
        ], $options);

        return $this->callAPI('answerCallbackQuery', $params);
    }

    public function answerInlineQuery(string $inlineQueryID, array $results,
        array $extra = [],
    ): array {
        $params = array_merge([
            'inline_query_id' => $inlineQueryID,
            'results'         => json_encode($results),
        ], $extra);

        return $this->callAPI('answerInlineQuery', $params);
    }
}