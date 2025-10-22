<?php
declare(strict_types=1); namespace App\Storage; use PDO, PDOException;
function make_pdo(): PDO{ $dsn=$_ENV['DB_DSN']??''; $user=$_ENV['DB_USER']??''; $pass=$_ENV['DB_PASS']??'';
  try{ $pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); return $pdo; }
  catch(PDOException $e){ throw new \RuntimeException('DB connection failed: '.$e->getMessage()); } }
