<?php

declare(strict_types=1);

namespace rifqydev\scribechat\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\getServer;
use pocketmine\player\Player;

class ScribeProcessorTask extends AsyncTask {

    private string $player;
    private string $message;
    private string $apiKey;
    private string $playerLanguagesSerialized;

    /**
     * @param array<string, string> $playerLanguages
     */
    public function __construct(string $player, string $message, string $apiKey, array $playerLanguages) {
        $this->player = $player;
        $this->message = $message;
        $this->apiKey = $apiKey;
        // Serialisasi array karena properti AsyncTask harus aman ditransfer antar-thread
        $this->playerLanguagesSerialized = serialize($playerLanguages);
    }

    public function onRun(): void {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $this->apiKey;

        // Meminta Gemini melakukan Sentiment Analysis dan deteksi toxic secara cerdas
        $prompt = "Analyze the following Minecraft chat message. If it is highly toxic, offensive, or severe hate speech, reply with 'TOXIC'. Otherwise, reply with 'CLEAN'. Message: \"" . $this->message . "\"";
        
        $payload = json_encode([
            "contents" => [
                ["parts" => [["text" => $prompt]]]
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Aman untuk lingkungan internal server game
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Timeout ketat agar thread cepat kembali

        $result = curl_exec($ch);
        curl_close($ch);

        $status = "CLEAN";
        if (is_string($result)) {
            $json = json_decode($result, true);
            $aiResponse = $json['candidates'][0]['content']['parts'][0]['text'] ?? "CLEAN";
            if (str_contains(strtoupper($aiResponse), "TOXIC")) {
                $status = "TOXIC";
            }
        }

        $this->setResult($status);
    }

    public function onCompletion(): void {
        $server = getServer();
        $status = $this->getResult();
        $playerInstance = $server->getPlayerExact($this->player);

        if ($status === "TOXIC") {
            if ($playerInstance instanceof Player) {
                $playerInstance->sendMessage("§c[ScribeShield] §7Pesan Anda diblokir karena terindikasi mengandung sentimen negatif.");
            }
            return;
        }

        // Jika bersih (CLEAN), teruskan pesan secara normal ke chat global server
        if ($playerInstance instanceof Player) {
            $server->broadcastMessage("<" . $playerInstance->getDisplayName() . "> " . $this->message);
        }
    }
}
