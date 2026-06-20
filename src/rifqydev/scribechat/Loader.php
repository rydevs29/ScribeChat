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
use rifqydev\scribechat\utils\SimpleForm;
use rifqydev\scribechat\task\ScribeProcessorTask;

class Loader extends PluginBase implements Listener {

    /** @var array<string, string> Menyimpan preferensi bahasa pemain [username => lang_code] */
    private array $playerLanguages = [];

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info(TextFormat::GREEN . "ScribeChat v1.0.0 oleh RifqyDev sukses dijalankan!");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if($command->getName() === "chat" && $sender instanceof Player) {
            $this->openMainMenu($sender);
            return true;
        }
        return false;
    }

    /**
     * Menangani Event Chat Pemain (Auto-Formatter, Sentiment Filter, & Auto Responder)
     */
    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $message = $event->getMessage();

        // 1. Auto-Formatter (Kapitalisasi awal kalimat otomatis & hapus spasi berlebih)
        $formattedMessage = ucfirst(trim(preg_replace('/\s+/', ' ', $message)));
        $event->setMessage($formattedMessage);

        // 2. Smart Auto-Responder (Deteksi Kata Kunci Ringan secara Sinkronus)
        if ($this->getConfig()->getNested("auto-responder.enabled", true)) {
            foreach ($this->getConfig()->getNested("auto-responder.trigger-keywords", []) as $keyword) {
                if (str_contains(strtolower($formattedMessage), strtolower($keyword))) {
                    $player->sendMessage($this->getConfig()->getNested("auto-responder.default-response"));
                    break;
                }
            }
        }

        // 3. Asynchronous Sentiment Analysis & Global Translation
        // Menggunakan AsyncTask agar koneksi HTTP luar tidak membuat server TPS drop/lag
        $apiKey = $this->getConfig()->getNested("api-settings.gemini-key", "");
        if ($apiKey !== "" && $apiKey !== "INPUT_YOUR_GEMINI_API_KEY_HERE") {
            $this->getServer()->getAsyncPool()->submitTask(new ScribeProcessorTask(
                $player->getName(),
                $formattedMessage,
                $apiKey,
                $this->playerLanguages
            ));
        }
    }

    /**
     * Antarmuka Utama Minimalis menggunakan Native Form UI PM5
     */
    public function openMainMenu(Player $player): void {
        $form = new SimpleForm(function(Player $player, ?int $data) {
            if ($data === null) return;

            switch ($data) {
                case 0:
                    $player->sendMessage("§b[ScribeChat] §7Ketik §e/ask <pertanyaan> §7untuk mengobrol langsung dengan AI.");
                    break;
                case 1:
                    $this->openLanguageMenu($player);
                    break;
                case 2:
                    $player->sendMessage("§a[ScribeChat] §7Terima kasih telah menjaga kenyamanan server!");
                    break;
            }
        });

        $form->setTitle("§l§8SCRIBE CHAT");
        $form->setContent("Selamat datang di pusat kendali obrolan pintar server.\nPilih layanan yang kamu butuhkan:");
        $form->addButton("🤖 Tanya Asisten AI\n§8[Buka ScribeAI]");
        $form->addButton("🌐 Pilih Bahasa Terjemahan\n§8[Ubah Preferensi]");
        $form->addButton("🛡️ Laporkan Chat Organik\n§8[Community Watch]");
        
        $player->sendForm($form);
    }

    public function openLanguageMenu(Player $player): void {
        $languages = ["id" => "Bahasa Indonesia", "en" => "English", "ja" => "Japanese"];
        $keys = array_keys($languages);

        $form = new SimpleForm(function(Player $player, ?int $data) use ($keys, $languages) {
            if ($data === null) return;
            
            $selectedLang = $keys[$data];
            $this->playerLanguages[$player->getName()] = $selectedLang;
            $player->sendMessage("§a[ScribeChat] §7Bahasa preferensi terjemahan kamu berhasil diubah ke: §b" . $languages[$selectedLang]);
        });

        $form->setTitle("§l§8PILIH BAHASA");
        $form->setContent("Pilih bahasa target. Setiap pesan asing di chat global akan otomatis diterjemahkan ke bahasa ini.");
        foreach ($languages as $name) {
            $form->addButton($name);
        }

        $player->sendForm($form);
    }
}
