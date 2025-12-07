<?php
require 'vendor/autoload.php';
$d = Dotenv\Dotenv::createImmutable(__DIR__);
$d->load();
$p = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$s = $p->query('SELECT c.id, c.company_name, cd.tenant FROM companies c JOIN company_credentials_devpos cd ON c.id = cd.company_id');
while($r = $s->fetch()) { 
    echo $r['id'] . ': ' . $r['company_name'] . ' (tenant: ' . $r['tenant'] . ')' . PHP_EOL;
}
