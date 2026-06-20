<?php

declare(strict_types=1);

namespace rifqydev\scribechat;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\sound\PopSound;
use rifqydev\scribechat\utils\SimpleForm;
use rifqydev\scribechat\task\ScribeProcessorTask;
use pocketmine\Server;

class Loader extends PluginBase implements Listener {

    private array $playerLanguages = [];
    private array $cooldowns = [];
    private array $lastMessages = [];

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "chat" && $sender instanceof Player) {
            $this->openMainMenu($sender);
            return true;
        }

        if ($command->getName() === "sc") {
            if (!$sender->hasPermission("scribechat.staff")) {
                $sender->sendMessage(TextFormat::RED . "Kamu tidak memiliki akses ke Staff Chat.");
                return true;
            }
            if (count($args) < 1) {
                $sender->sendMessage(TextFormat::YELLOW . "Penggunaan: /sc <pesan>");
                return true;
            }
            $message = implode(" ", $args);
            $format = str_replace(["{name}", "{msg}"], [$sender->getName(), $message], $this->getConfig()->getNested("chat-format.staff"));
            
            foreach ($this->getServer()->getOnlinePlayers() as $p) {
                if ($p->hasPermission("scribechat.staff")) {
                    $p->sendMessage($format);
                }
            }
            return true;
        }
        return false;
    }

    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $message = $event->getMessage();
        $config = $this->getConfig();

        // 1. Anti-Spam & Cooldown
        if (!$player->hasPermission("scribechat.bypass")) {
            if ($config->getNested("features.anti-spam.enabled")) {
                $time = time();
                if (isset($this->cooldowns[$player->getName()]) && ($time - $this->cooldowns[$player->getName()]) < $config->getNested("features.anti-spam.cooldown-seconds")) {
                    $player->sendMessage("§c[ScribeChat] §7Tunggu beberapa detik sebelum mengirim pesan lagi.");
                    $event->cancel();
                    return;
                }
                if ($config->getNested("features.anti-spam.block-duplicate") && isset($this->lastMessages[$player->getName()]) && $this->lastMessages[$player->getName()] === $message) {
                    $player->sendMessage("§c[ScribeChat] §7Tolong jangan mengirim pesan yang sama persis (Spam).");
                    $event->cancel();
                    return;
                }
                $this->cooldowns[$player->getName()] = $time;
                $this->lastMessages[$player->getName()] = $message;
            }
        }

        // 2. Anti-Capslock & Regex Filter (IP/Links)
        if ($config->getNested("features.anti-capslock")) {
            if (preg_match_all('/[A-Z]/', $message) > (strlen($message) / 2) && strlen($message) > 5) {
                $message = ucfirst(strtolower($message));
            }
        }
        if ($config->getNested("features.block-links")) {
            if (preg_match('/(https?:\/\/[^\s]+)|(\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b)|([a-zA-Z0-9-]+\.[a-zA-Z]{2,})/', $message)) {
                $player->sendMessage("§c[ScribeChat] §7Dilarang mengirimkan link atau IP server lain.");
                $event->cancel();
                return;
            }
        }

        // 3. Smart Mentions
        if ($config->getNested("features.smart-mentions")) {
            foreach ($this->getServer()->getOnlinePlayers() as $target) {
                $name = $target->getName();
                if (stripos($message, "@" . $name) !== false) {
                    $message = str_ireplace("@" . $name, "§b@" . $name . "§f", $message);
                    $target->broadcastSound(new PopSound(), [$target]);
                    $target->sendTitle(" ", "§bKamu dimention oleh " . $player->getName(), 10, 40, 10);
                }
            }
        }

        // 4. Radius (Local) vs Global Chat
        $isGlobal = false;
        if ($config->getNested("features.local-chat.enabled")) {
            if (str_starts_with($message, "!")) {
                $isGlobal = true;
                $message = substr($message, 1); // Hapus tanda "!"
            }
        } else {
            $isGlobal = true;
        }

        $event->cancel(); // Kita cancel event bawaan, karena kita akan handle broadcast sendiri (via AI atau manual)

        // 5. Send to AI Processor (Sentiment + Grammar Fix)
        $apiKey = $config->getNested("api-settings.gemini-key", "");
        if ($apiKey !== "" && $apiKey !== "INPUT_YOUR_GEMINI_API_KEY_HERE" && $config->getNested("features.ai-grammar-fixer")) {
            $this->getServer()->getAsyncPool()->submitTask(new ScribeProcessorTask(
                $player->getName(),
                $message,
                $apiKey,
                $this->playerLanguages,
                $isGlobal,
                $config->getAll()
            ));
        } else {
            // Fallback manual broadcast jika AI mati
            $this->broadcastChat($player, $message, $isGlobal, $config->getAll());
        }
    }

    public function broadcastChat(Player $sender, string $message, bool $isGlobal, array $config): void {
        $formatKey = $isGlobal ? "chat-format.global" : "chat-format.local";
        $format = str_replace(["{name}", "{msg}"], [$sender->getName(), $message], $config[$formatKey] ?? "§7{name} §8» §f{msg}");

        if ($isGlobal) {
            $this->getServer()->broadcastMessage($format);
            $this->sendToDiscordWebhook($format, $config);
        } else {
            $radius = $config["features"]["local-chat"]["radius"] ?? 50;
            foreach ($this->getServer()->getOnlinePlayers() as $p) {
                if ($p->getWorld() === $sender->getWorld() && $p->getPosition()->distance($sender->getPosition()) <= $radius) {
                    $p->sendMessage($format);
                }
            }
        }
    }

    private function sendToDiscordWebhook(string $message, array $config): void {
        $url = $config["webhook"]["discord-url"] ?? "";
        if ($url !== "") {
            $cleanMessage = TextFormat::clean($message); // Hapus warna PMMP
            // Logic cURL webhook discord bisa di-async-kan disini
        }
    }

    // (Biarkan fungsi openMainMenu dan openLanguageMenu dari kode sebelumnya ada di sini)
    // ...
}
