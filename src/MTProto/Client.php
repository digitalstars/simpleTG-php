<?php

namespace DigitalStars\SimpleTG\MTProto;

class Client {

    protected array $rsaKeys = [
        "-----BEGIN RSA PUBLIC KEY-----\n".
        "MIIBCgKCAQEA6LszBcC1LGzyr992NzE0ieY+BSaOW622Aa9Bd4ZHLl+TuFQ4lo4g\n".
        "5nKaMBwK/BIb9xUfg0Q29/2mgIR6Zr9krM7HjuIcCzFvDtr+L0GQjae9H0pRB2OO\n".
        "62cECs5HKhT5DZ98K33vmWiLowc621dQuwKWSQKjWf50XYFw42h21P2KXUGyp2y/\n".
        "+aEyZ+uVgLLQbRA1dEjSDZ2iGRy12Mk5gpYc397aYp438fsJoHIgJ2lgMv5h7WY9\n".
        "t6N/byY9Nw9p21Og3AoXSL2q/2IJ1WRUhebgAdGVMlV1fkuOQoEzR7EdpqtQD9Cs\n".
        "5+bfo3Nhmcyvk5ftB0WkJ9z6bNZ7yxrP8wIDAQAB\n".
        '-----END RSA PUBLIC KEY-----',
    ];
    /**
     * Test RSA keys.
     */
    protected array $testRsaKeys =  [
        "-----BEGIN RSA PUBLIC KEY-----\n".
        "MIIBCgKCAQEAyMEdY1aR+sCR3ZSJrtztKTKqigvO/vBfqACJLZtS7QMgCGXJ6XIR\n".
        "yy7mx66W0/sOFa7/1mAZtEoIokDP3ShoqF4fVNb6XeqgQfaUHd8wJpDWHcR2OFwv\n".
        "plUUI1PLTktZ9uW2WE23b+ixNwJjJGwBDJPQEQFBE+vfmH0JP503wr5INS1poWg/\n".
        "j25sIWeYPHYeOrFp/eXaqhISP6G+q2IeTaWTXpwZj4LzXq5YOpk4bYEQ6mvRq7D1\n".
        "aHWfYmlEGepfaYR8Q0YqvvhYtMte3ITnuSJs171+GDqpdKcSwHnd6FudwGO4pcCO\n".
        "j4WcDuXc2CTHgH8gFTNhp/Y8/SpDOhvn9QIDAQAB\n".
        '-----END RSA PUBLIC KEY-----',
    ];
    private int $api_id;
    private string $api_hash;
    private $connection;
    private Session $session;

    private Transport $transport;
    private string $transport_type;

    private int $messageCounter = 0;

    public function __construct(array $settings) {
        $this->api_id = $settings['api_id'];
        $this->api_hash = $settings['api_hash'];
        $this->transport_type = $settings['transport_type'];
        $this->connection = (new Connection('149.154.167.50', transport: 'Intermediate'))->connect();
        $this->transport = new Transport();
//        $this->session = new Session();
    }

    public function load(TL $TL, string $rsa_key): self
    {
        $key = \phpseclib3\Crypt\RSA::load($rsa_key);

        $modulus = Tools::getVar($this->rsaKeys, 'modulus');
        $exponent = Tools::getVar($this->rsaKeys, 'exponent');

        $fp_data = $TL->serializeObject(['type' => 'bytes'], $modulus->toBytes(), 'key') .
            $TL->serializeObject(['type' => 'bytes'], $exponent->toBytes(), 'key');

        $fingerprint = substr(sha1($fp_data, true), -8);

        return new self(
            modulus: $modulus,
            exponent: $exponent,
            fingerprint: $fingerprint
        );
    }


    /**
     * Инициализация DH-обмена ключами.
     */
    public function initiateDHExchange(): array {
        // Генерация nonce
        $nonce = $this->generateNonce();

        // Формирование запроса req_pq_multi
        $reqPQ = $this->createReqPQ($nonce);

        $msg = $this->prepareMessage($reqPQ);

        // Отправка запроса и получение ответа
        $response = $this->sendMessage($msg);

        $status = substr($response, 0, 4);  // Берем первые 4 байта (статус)
        $status = $this->reverseBytes(substr(bin2hex($status), 0, 8));  // Переворачиваем 4 байта (8 hex-символов)

        $response = substr($response, 4);  // Удаляем эти 4 байта из данных

        // Десериализация ответа (для req_pq_multi)
        return $this->parseResPQ($response);
    }

    public function reverseBytes($hex) {
        return implode('', array_reverse(str_split($hex, 2)));
    }

    private function generateNonce($length = 16): string {
        return random_bytes($length); // Генерация 128-битного случайного числа
    }

    private function createReqPQ($nonce): string {
        $constructorId = 0xbe7e8ef1; // CRC32 от "req_pq_multi nonce:int128 = ResPQ"
        return Serializer::serializeInt32($constructorId) . Serializer::serializeInt128($nonce);
    }

    public function parseResPQ(string $data): array {
//        var_dump(bin2hex($data));

        $hexData = bin2hex($data);

        // Вспомогательная функция для извлечения данных
        $extract_bytes = function (int $offset, int $length, bool $reverse = false) use ($hexData): string {
            // умножаем на 2, чтобы получить длину в hex-символах
            $slice = substr($hexData, $offset * 2, $length * 2);
            return $reverse ? $this->reverseBytes($slice) : $slice;
        };

        $auth_key_id = $extract_bytes(0, 8);
        $message_id = $extract_bytes(8, 8);
        $message_length = hexdec($extract_bytes(16, 4, true));
        $resPQ = $extract_bytes(20, 4);
        $nonce = $extract_bytes(24, 16, true);
        $server_nonce = $extract_bytes(40, 16, true);
        $pq = substr($data, 56, 12); //todo похоже из-за этого криво получается
        $vectorConstructor = $extract_bytes(68, 4);
        $count = hexdec($extract_bytes(72, 4, true));

        if ($vectorConstructor !== '15c4b51c') {
            trigger_error("Invalid vector constructor number", E_USER_WARNING);
        }

        // Чтение fingerprints
        $fingerprints = [];
        for ($i = 0; $i < $count; $i++) {
            $fingerprints[] = $extract_bytes(76 + $i * 8, 8); // 8 байт для каждого отпечатка
        }

        return [
            'auth_key_id' => $auth_key_id,
            'message_id' => $message_id,
            'message_length' => $message_length,
            'resPQ' => $resPQ,
            'nonce' => $nonce,
            'server_nonce' => $server_nonce,
            'pq' => $pq,
            'vector_constructor' => $vectorConstructor,
            'fingerprints_count' => $count,
            'server_public_key_fingerprints' => $fingerprints,
        ];
    }


    function rsaFingerprint($n, $e) {
        // Сериализация публичного ключа в формат байтов (аналог to_binary)
        $nStr = bin2hex($n); // Преобразуем число n в строку байтов
        $eStr = bin2hex($e); // Преобразуем число e в строку байтов

        // Объединение n и e в одну строку
        $publicKey = $nStr . $eStr;

        // Хэширование публичного ключа с помощью SHA-1
        $sha1Hash = sha1(hex2bin($publicKey), true); // true для получения хэша в бинарном виде

        // Получаем 64 младших бита из хэша (старшие 8 байтов)
        $fingerprint = unpack('P', substr($sha1Hash, 12, 8))[1]; // 'P' означает 64-битное значение

        return $fingerprint;
    }

    public function sendMessage(string $msg, string $dump = null): false|string {
        // Обработка транспортов
        if ($this->transport_type === 'Abridged') {
            $msg = $this->transport->processAbridged($msg);
        } elseif ($this->transport_type === 'Intermediate') {
            $msg = $this->transport->processIntermediate($msg);
        } elseif ($this->transport_type === 'PaddedIntermediate') {
            $msg = $this->transport->processPaddedIntermediate($msg);
        } elseif ($this->transport_type === 'Full') {
            $msg = $this->transport->processFull($msg);
        }

        // Логирование отладочной информации
        if ($dump !== null) {
            self::logHexDebug($msg, 'Sending ' . $dump);
        }

        socket_write($this->connection, $msg, strlen($msg));
        socket_set_option($this->connection, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 5, "usec" => 0]);
        $response = socket_read($this->connection, 4096, PHP_BINARY_READ);

        if ($response === false) {
            throw new Exception("Error reading from socket: " . socket_strerror(socket_last_error($this->connection)));
        }

        return $response;
    }

    /**
     * Генерация уникального message_id.
     */
    public function generateMessageId(): string
    {
        $timestamp = time(); // Текущее время в секундах
        $messageId = ($timestamp << 32) | ($this->messageCounter * 4);
        $this->messageCounter++;

        return pack('P', $messageId); // 64-битное значение в формате little-endian
    }

    /**
     * Формирование сообщения с транспортными данными.
     */
    public function prepareMessage(string $msg): string
    {
        $authKeyId = pack('P', 0); // auth_key_id — всегда 0 для plain-text сообщений
        $messageId = $this->generateMessageId(); // Уникальный message_id
        $messageLength = pack('V', strlen($msg)); // Длина полезной нагрузки (4 байта little-endian)

        // Полное сообщение с заголовком
        return $authKeyId . $messageId . $messageLength . $msg;
    }

    // Логирование отладочной информации
    public static function logHexDebug(string $msg, string $label): void {
        echo $label . " : " . bin2hex($msg) . "\n";
    }
}