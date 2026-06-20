<?php

declare(strict_types=1);

namespace rifqydev\scribechat\utils;

use pocketmine\form\Form;
use pocketmine\player\Player;

class SimpleForm implements Form {

    private array $formArr = [];
    /** @var callable */
    private $callable;

    public function __construct(callable $callable) {
        $this->callable = $callable;
        $this->formArr["type"] = "form";
        $this->formArr["title"] = "";
        $this->formArr["content"] = "";
        $this->formArr["buttons"] = [];
    }

    public function setTitle(string $title): void {
        $this->formArr["title"] = $title;
    }

    public function setContent(string $content): void {
        $this->formArr["content"] = $content;
    }

    public function addButton(string $text): void {
        $this->formArr["buttons"][] = ["text" => $text];
    }

    public function handleResponse(Player $player, $data): void {
        ($this->callable)($player, $data);
    }

    public function jsonSerialize(): array {
        return $this->formArr;
    }
}
