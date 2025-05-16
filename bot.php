<?php
require 'vendor/autoload.php';
use ZhenyaGR\TGZ\TGZ as tg;

const BOT_TOKEN = 'YourBotToken';

$tg = tg::create(BOT_TOKEN);

$tg->initVars(user_id: $user_id, text: $text, type: $type, callback_data: $callback_data, query_id: $query_id);
// Инициализируем переменные

if ($type === 'bot_command') {
    // bot_command - обычное сообщение, которое содержит команду "/{command}"
    if ($text === '/start') {
        $tg->msg("Привет, это пример из статьи про inline-mode!\nУ меня есть 2 inline-команды, чтобы их посмотреть, введи @inline321Bot ...")
            ->send();
    }
} else if ($type === 'inline_query') {
    // Если тип запроса inline_query

    if ($text === '') {
        // Если пользователь ничего не ввел, то выводим 1-ю команду

        $answer_params = [
            $tg->inline('article')
                ->id('inline1')
                ->title('Нажми меня!!')
                ->description('Выводит подсказку для второй')
                ->text('Чтобы узнать вторую команду, введи <code>@inline321Bot button</code>')
                ->parseMode('HTML')
                ->create(),
        ];

        // Отвечаем на запрос телеграма по $query_id
        $tg->answerInlineQuery($query_id, $answer_params);

    } elseif ($text === 'button') {
        // Если пользователь ввел "button", то выводим 2-ю команду
        $answer_params = [
            $tg->inline('article')
                ->id('inline2')
                ->title('Отправить кнопки')
                ->description('Показывает кнопки')
                ->text('Нажми на <i>кнопку</i> снизу, чтобы изменить текст')
                ->parseMode('HTML')
                ->kbd([[$tg->buttonCallback('Кнопка', 'call')]])
                ->create(),
        ];
        $tg->answerInlineQuery($query_id, $answer_params);

    } else if ($text === 'image') {
        // Если пользователь ввел "image", то выводим 3-ю команду
        $answer_params = [
            [
                'type' => 'photo', // Тип результата — фото
                'id' => 'id2', // Уникальный ID результата
                'photo_url' => 'http://12-kanal.ru/upload/iblock/62a/zb1mq2841smduhwwuv3jwjfv9eooyc50/fotograf3.jpg', // URL изображения
                'thumb_url' => 'http://12-kanal.ru/upload/iblock/62a/zb1mq2841smduhwwuv3jwjfv9eooyc50/fotograf3.jpg', // Превью (иконка)
                'title' => 'Заголовок фото',        // Игнорируются телеграмом
                'description' => 'Описание фото',   // Игнорируются телеграмом
                'caption' => '<b>Улыбку</b>',
                'parse_mode' => 'HTML',
            ]
        ];

        $tg->answerInlineQuery($query_id, $answer_params);

    }


} else if ($type === 'callback_query') {
    // Если тип запроса callback_query

    $tg->answerCallbackQuery($query_id, ['text' => "Вы нажали кнопку!"]);

    if ($callback_data === 'call') {
        // Если пользователь нажал на кнопку
        $tg->msg("Текст изменён!\nТретья команда — <code>@inline321Bot image</code>")
            ->parseMode('HTML')
            ->sendEdit();
    }
}