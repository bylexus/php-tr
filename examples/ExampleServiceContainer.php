<?php

use PHPMailer\PHPMailer\PHPMailer;
use Psr\Container\ContainerInterface;

class ExampleServiceContainer implements ContainerInterface {
    private $services = [];
    public function __construct() {
        $this->services[PHPMailer::class] = $this->createMailer();
    }

    public function get(string $id) {
        return $this->services[$id] ?? null;
    }

    public function has(string $id): bool {
        return key_exists($id, $this->services);
    }

    private function createMailer(): PHPMailer {
        $mailer = new PHPMailer(true);
        $mailer->IsSMTP();
        $mailer->Host = 'localhost';
        $mailer->Port = '1025';
        $mailer->CharSet = "utf-8";
        return $mailer;
    }
}
