<?php
namespace helpers;

use PDO;

final class RespostasCadernoHelper
{
    public static function salvar(PDO $db, string $hashCaderno, array $respostasNovas): void
    {
        $questoes = [
            "1","2","3","4","5A","5B","5C","6","7A","7B","7C","7D","7E","8","9",
            "10A","10B","10C","10D","10E","10F","11A","11B","11C","12A","12B",
            "12C","12D","12E","12F","12G","13A","13B","13C","13D","13E","13F",
            "13G","13H","13I","14","15A","15B","16","17","18","19","20","21A",
            "21B","21C","21D","21E","22A","22B","22C","22D","22E","22F","22G",
            "22H","23A","23B","23C","23D","23E","23F","23G","23H","23I"
        ];

        // 1. Buscar existentes
        $sqlSel = "SELECT * FROM respostas_caderno WHERE IDENTIFICADOR_CADERNO = :id";
        $stmtSel = $db->prepare($sqlSel);
        $stmtSel->execute([':id' => $hashCaderno]);
        $existentes = $stmtSel->fetch(PDO::FETCH_ASSOC) ?: [];

        // 2. Merge
        $respostasFinais = [];
        foreach ($questoes as $q) {
            $nova      = $respostasNovas[$q] ?? '';
            $existente = $existentes[$q] ?? '';
            $respostasFinais[$q] = ($nova === '' || $nova === null) ? $existente : $nova;
        }

        // 3. UPSERT
        $colunas      = '`' . implode('`,`', $questoes) . '`';
        $placeholders = implode(',', array_fill(0, count($questoes), '?'));
        $updates      = implode(', ', array_map(fn($q) => "`{$q}` = VALUES(`{$q}`)", $questoes));

        $sql = "
            INSERT INTO respostas_caderno (IDENTIFICADOR_CADERNO, {$colunas})
            VALUES (?, {$placeholders})
            ON DUPLICATE KEY UPDATE {$updates}, updated_at = CURRENT_TIMESTAMP
        ";

        $stmt   = $db->prepare($sql);
        $params = array_merge([$hashCaderno], array_values($respostasFinais));
        $stmt->execute($params);
    }
}
