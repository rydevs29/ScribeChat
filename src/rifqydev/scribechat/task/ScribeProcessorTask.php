<?php

declare(strict_types=1);

namespace rifqydev\scribechat\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\player\Player;

class ScribeProcessorTask extends AsyncTask {

    private string $player;
    private string $message;
    private string $apiKey;
    private string $playerLanguagesSerialized;
    private bool $isGlobal;
    private string $configSerialized;

    public function __construct(string $player, string $message, string $apiKey, array $playerLanguages, bool $isGlobal, array $config) {
        $this->player = $player;
        $this->message = $message;
        $this->apiKey = $apiKey;
        $this->playerLanguagesSerialized = serialize($playerLanguages);
        $this->isGlobal = $isGlobal;
        $this->configSerialized = serialize($config);
    }

    public function onRun(): void {
        $config = unserialize($this->configSerialized);
        $models = $config['api-settings']['dynamic-models'];

        // --- DYNAMIC AI ROUTING LOGIC ---
        $messageLength = strlen($this->message);
        $wordCount = str_word_count($this->message);
        $isQuestion = str_contains($this->message, '?');

        $selectedModel = $models['flash-v1']; // Default model

        if ($isQuestion || $messageLength >= 70) {
            // Kompleks: Butuh reasoning tinggi untuk sentimen & perbaikan tata bahasa
            $selectedModel = $models['flash-v2'];
        } elseif ($messageLength >= 40) {
            // Menengah: Kalimat standar
            $selectedModel = $models['flash-v1'];
        } elseif ($messageLength >= 15) {
            // Ringan-Sedang: Info singkat
            $selectedModel = $models['lite-v2'];
        } else {
            // Sangat Ringan: "Halo", "Ok", "Gas"
            $selectedModel = $models['lite-v1'];
        }
        // --------------------------------

        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $selectedModel . ":generateContent?key=" . $this->apiKey;

        // Prompt dibuat se-efisien mungkin
        $prompt = "You are a Minecraft chat processor. Rule 1: If the message is severe hate speech/toxic, reply ONLY with 'TOXIC'. Rule 2: If clean, fix typos into readable Indonesian (or English). Do not change the meaning. Reply ONLY with the fixed text. Message: \"" . $this->message . "\"";
        
        $payload = json_encode([
            "contents" => [["parts" => [["text" => $prompt]]]]
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4); 

        $result = curl_exec($ch);
        curl_close($ch);

        $finalMessage = $this->message; 
        if (is_string($result)) {
            $json = json_decode($result, true);
            $aiResponse = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? "");
            if ($aiResponse !== "") {
                $finalMessage = $aiResponse;
            }
        }

        // Simpan hasil untuk dilempar kembali ke thread utama
        $this->setResult($finalMessage);
    }

    public function onCompletion(): void {
        $server = Server::getInstance();
        $result = $this->getResult();
        $playerInstance = $server->getPlayerExact($this->player);
        $config = unserialize($this->configSerialized);

        if ($result === "TOXIC") {
            if ($playerInstance instanceof Player) {
                $playerInstance->sendMessage("§c[ScribeShield] §7Pesan diblokir karena indikasi *toxic*.");
            }
            return;
        }

        $plugin = $server->getPluginManager()->getPlugin("ScribeChat");
        if ($plugin !== null && $playerInstance instanceof Player) {
            $plugin->broadcastChat($playerInstance, $result, $this->isGlobal, $config);
        }
    }
}
