<?php

class Coleta
{
    private static function getDb(): PDO
    {
        static $pdo = null;
        if (!$pdo) {
            $pdo = new PDO(
                'mysql:host=localhost;port=23306;dbname=corretor_saeb;charset=utf8mb4',
                'root',
                'root',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return $pdo;
    }

    public static function listarTodas(): array
    {
        $db = self::getDb();
        $stmt = $db->query("SELECT id, codigo, nome, modelo_questionario FROM coletas ORDER BY nome ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
