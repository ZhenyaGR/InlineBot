<?php

namespace ZhenyaGR\TGZ;

use CURLFile;

final class Message
{
    public ?string $text;
    public object $TGZ;
    public ?int $chatID = 0;
    public array $reply_to = [];
    public array $kbd = [];
    public string $parse_mode;
    public array $params_additionally = [];
    public bool $sendPhoto = false;
    public bool $sendAnimation = false;
    public bool $sendPoll = false;
    public bool $sendDocument = false;
    public bool $sendSticker = false;
    public bool $sendVideo = false;
    public bool $sendAudio = false;
    public bool $sendVoice = false;
    public bool $sendDice = false;
    public bool $sendMediaGroup = false;
    public string $question = '';
    public array $media = [];
    public string $sticker_id = '';
    public array $files = [];
    public array $options = [];
    public array $buttons = [];
    public bool $is_anonymous = false;
    public string $pollType = "regular";


    public function __construct($text, $TGZ)
    {
        $this->text = $text;
        $TGZ->initChatID($this->chatID);
        $this->parse_mode = $TGZ->parseModeDefault;
        $this->TGZ = $TGZ;
    }

    public function kbd(
        array $buttons = [],
        bool $inline = false,
        bool $one_time_keyboard = false,
        bool $resize_keyboard = false,
        bool $remove_keyboard = false,
    ): self {
        if ($remove_keyboard) {
            $keyboardConfig = ['remove_keyboard' => true];
            $this->kbd = [
                'reply_markup' => json_encode(
                    $keyboardConfig, JSON_THROW_ON_ERROR,
                ),
            ];

            return $this;
        }

        $kbd = $inline
            ? ['inline_keyboard' => $buttons]
            : [
                'keyboard'          => $buttons,
                'resize_keyboard'   => $resize_keyboard,
                'one_time_keyboard' => $one_time_keyboard,
            ];

        $this->kbd = [
            'reply_markup' => json_encode($kbd, JSON_THROW_ON_ERROR),
        ];
        $this->buttons = $buttons;

        return $this;
    }

    public function parseMode(string $mode = ''): static
    {
        if ($mode !== 'HTML' && $mode !== 'Markdown' && $mode !== 'MarkdownV2'
            && $mode !== ''
        ) {
            $mode = '';
        }
        $this->parse_mode = $mode;

        return $this;
    }

    public function params(array $params = []): static
    {
        $this->params_additionally = $params;

        return $this;
    }

    public function reply(?int $reply_to_message_id = null): static
    {
        if ($reply_to_message_id === null) {
            $msg_id = $this->TGZ->update['message']['message_id'] ??
                $this->TGZ->update['callback_query']['message']['message_id'];
        } else {
            $msg_id = $reply_to_message_id;
        }
        $this->reply_to = ['reply_to_message_id' => $msg_id];

        return $this;
    }

    private function processMediaGroup(array $files, string $type): static
    {
        foreach ($files as $file) {
            if ($this->detectInputType($file)) {
                // Если требуется загрузка (локальный файл или URL)
                $fileIndex = count($this->media) + 1;
                $attachKey = 'attach://file'.$fileIndex;
                $this->media[] = [
                    'type'  => $type,
                    'media' => $attachKey,
                ];
                // Сохраняем объект CURLFile в отдельном массиве
                $this->files['file'.$fileIndex] = new CURLFile($file);
            } else {
                // Если передан file_id
                $this->media[] = [
                    'type'  => $type,
                    'media' => $file,
                ];
            }
        }

        return $this;
    }

    private function detectInputType($input): bool
    {
        // Проверка на URL
        if (filter_var($input, FILTER_VALIDATE_URL)) {
            return true;
        }
        // Проверка на локальный файл
        if (file_exists($input) && is_file($input)) {
            return true;
        }

        // Иначе file_id
        return false;
    }

    public function dice(string $dice): static
    {
        $this->sendDice = true;
        $this->text = $dice;

        return $this;
    }

    public function gif(string|array $url): static
    {
        $url = is_array($url) ? $url : [$url];
        $this->processMediaGroup($url, 'document');
        $this->sendAnimation = true;

        return $this;
    }

    public function voice(string $url): static
    {
        $url = [$url];
        $this->processMediaGroup($url, 'voice');
        $this->sendVoice = true;

        return $this;
    }

    public function audio(string|array $url): static
    {
        $url = is_array($url) ? $url : [$url];
        $this->processMediaGroup($url, 'audio');
        $this->sendAudio = true;

        return $this;
    }

    public function video(string|array $url): static
    {
        $url = is_array($url) ? $url : [$url];
        $this->processMediaGroup($url, 'video');
        $this->sendVideo = true;

        return $this;
    }

    public function doc(string|array $url): static
    {
        $url = is_array($url) ? $url : [$url];
        $this->processMediaGroup($url, 'document');
        $this->sendDocument = true;

        return $this;
    }

    public function img(string|array $url): static
    {
        $url = is_array($url) ? $url : [$url];
        $this->processMediaGroup($url, 'photo');
        $this->sendPhoto = true;

        return $this;
    }

    public function urlImg(string $url): static
    {
        $this->text = '<a href="'.htmlspecialchars(
                $url,
            ).'">​</a>'.$this->text; // Использует пробел нулевой ширины
        $this->parse_mode               // со ссылкой в начале сообщения
            = "HTML";

        return $this;
    }

    public function poll(string $text): static
    {
        $this->sendPoll = true;
        $this->question = $text;

        return $this;
    }

    public function sticker(string $file_id): static
    {
        $this->sendSticker = true;
        $this->sticker_id = $file_id;

        return $this;
    }

    public function addAnswer(string $text): static
    {
        $this->options[] = $text;

        return $this;
    }

    public function isAnonymous(?bool $anon = true): static
    {
        $this->is_anonymous = $anon;

        return $this;
    }

    public function pollType(string $type): static
    {
        $this->pollType = $type;

        return $this;
    }

    public function action(?string $action = 'typing'): static
    {
        if (!in_array($action, [
            'typing',
            'upload_photo',
            'upload_video',
            'record_video',
            'record_voice',
            'upload_voice',
            'upload_document',
            'choose_sticker',
            'find_location',
            'record_video_note',
            'upload_video_note',
        ])
        ) {
            $action = 'typing';
        }
        $this->TGZ->callAPI(
            'sendChatAction', ['chat_id' => $this->chatID, 'action' => $action],
        );

        return $this;
    }

    public function send(?int $chatID = null): array
    {
        $params = [
            'chat_id' => $chatID ?: $this->chatID,
        ];
        $params = array_merge($params, $this->params_additionally);
        $params = array_merge($params, $this->reply_to);
        $params = array_merge($params, $this->kbd);

        if (!$this->sendPhoto && !$this->sendAudio && !$this->sendSticker
            && !$this->sendDice
            && !$this->sendVoice
            && !$this->sendPoll
            && !$this->sendVideo
            && !$this->sendAnimation
            && !$this->sendDocument
            && !$this->sendMediaGroup
        ) {
            $params['text'] = $this->text;
            $params['parse_mode'] = $this->parse_mode;

            return $this->TGZ->callAPI('sendMessage', $params);
        }

        if (count($this->media) > 1 && !$this->sendVoice) {
            return $this->sendMediaGroup($params);
        }

        return $this->sendMediaType($params);
    }

    public function sendEdit(?string $messageID = null, ?int $chatID = null, ?string $messageIDInit = null): array
    {
        $this->TGZ->initMsgID($messageIDInit);
        if (isset($this->TGZ->update['callback_query']['message']['message_id'])) {

            $identifier = [
                'chat_id'    => $chatID ?: $this->chatID,
                'message_id' => $messageID ?: $messageIDInit,
            ];
        } else {
            $identifier = [
                'inline_message_id' => $messageIDInit,
            ];
        }

        $params = [
            'text'       => $this->text,
            'parse_mode' => $this->parse_mode,
        ];

        $params = array_merge($params, $identifier);
        $params = array_merge($params, $this->kbd);
        $params = array_merge($params, $this->params_additionally);

        $method = 'editMessageText';

        return $this->TGZ->callAPI($method, $params);
    }



    /**
     * @param (int|mixed)[]                       $params
     *
     * @psalm-param array{chat_id: int|mixed,...} $params
     *
     * @psalm-return array<never, never>
     */
    private function sendMediaGroup(array $params): array
    {
        $params1 = [
            'caption'    => $this->text,
            'parse_mode' => $this->parse_mode,
        ];

        $this->media[0] = array_merge($this->media[0], $params1);
        $mediaChunks = array_chunk($this->media, 10);

        foreach ($mediaChunks as $mediaChunk) {
            $postFields = array_merge($params, [
                'media' => json_encode($mediaChunk, JSON_THROW_ON_ERROR),
            ]);

            foreach ($mediaChunk as $item) {
                if (strpos($item['media'], 'attach://') === 0) {
                    $fileKey = str_replace('attach://', '', $item['media']);
                    $postFields[$fileKey] = $this->files[$fileKey];
                }
            }
            $this->TGZ->callAPI('sendMediaGroup', $postFields);
        }

        return [];
    }

    private function sendPoll($params): array
    {
        $params['question'] = $this->question;
        $params['options'] = json_encode($this->options, JSON_THROW_ON_ERROR);
        $params['is_anonymous'] = $this->is_anonymous;
        $params['type'] = $this->pollType;

        return $this->TGZ->callAPI('sendPoll', $params);
    }

    private function sendSticker($params): array
    {
        $params['sticker'] = $this->sticker_id;

        return $this->TGZ->callAPI('sendSticker', $params);
    }

    /**
     * @param (int|mixed)[]                       $params
     *
     * @psalm-param array{chat_id: int|mixed,...} $params
     */
    private function sendMediaType(array $params): array
    {
        if ($this->sendPhoto) {
            return $this->mediaSend('photo', $params);
        }

        if ($this->sendDocument) {
            return $this->mediaSend('document', $params);
        }

        if ($this->sendVideo) {
            return $this->mediaSend('video', $params);
        }

        if ($this->sendAnimation) {
            return $this->mediaSend('animation', $params);
        }

        if ($this->sendAudio) {
            return $this->mediaSend('audio', $params);
        }

        if ($this->sendVoice) {
            return $this->mediaSend('voice', $params);
        }

        if ($this->sendDice) {
            $params['emoji'] = $this->text;

            return $this->TGZ->callAPI('sendDice', $params);
        }

        if ($this->sendPoll) {
            return $this->sendPoll($params);
        }

        if ($this->sendSticker) {
            return $this->sendSticker($params);
        }

        return [];
    }

    private function mediaSend(string $type, $params)
    {
        $params['caption'] = $this->text;
        $params['parse_mode'] = $this->parse_mode;
        $params[$type] = strpos(
            $this->media[0]['media'],
            'attach://',
        ) !== false ? $this->files['file1'] : $this->media[0]['media'];

        return $this->TGZ->callAPI('send'.ucfirst($type), $params);
    }

}


