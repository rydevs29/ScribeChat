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
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $this->apiKey;

        // Prompt super komprehensif (Sentiment + Grammar)
        $prompt = "You are a chat processor for a Minecraft server. Rule 1: If the message is severe hate speech or highly toxic, reply exactly with 'TOXIC'. Rule 2: If it's clean, fix any extreme typos or messy slang into natural, readable Indonesian (or English if originally English). Do NOT change the meaning. Reply ONLY with the fixed text. Message: \"" . $this->message . "\"";
        
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

        $this->setResult($finalMessage);
    }

    public function onCompletion(): void {
        $server = Server::getInstance();
        $result = $this->getResult();
        $playerInstance = $server->getPlayerExact($this->player);
        $config = unserialize($this->configSerialized);

        if ($result === "TOXIC") {
            if ($playerInstance instanceof Player) {
                $playerInstance->sendMessage("§c[ScribeShield] §7Pesan kamu diblokir karena terindikasi *toxic*.");
            }
            return;
        }

        // Broadcast menggunakan fungsi dari Loader
        $plugin = $server->getPluginManager()->getPlugin("ScribeChat");
        if ($plugin !== null && $playerInstance instanceof Player) {
            // Gunakan method broadcastChat di Loader
            $plugin->broadcastChat($playerInstance, $result, $this->isGlobal, $config);
        }
    }
}
