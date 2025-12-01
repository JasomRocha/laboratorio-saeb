<?php

class LoteCorrecao
{
    private static ?PDO $db = null;

    public static function setDb(PDO $pdo): void
    {
        self::$db = $pdo;
    }

    private static function getDb(): PDO
    {
        if (!self::$db) {
            self::$db = new PDO(
                'mysql:host=localhost;port=23306;dbname=corretor_saeb;charset=utf8mb4',
                'root',
                'root',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return self::$db;
    }

    /**
     * Adiciona um pacote de correção ao banco e faz upload para S3
     */
    public static function adicionaPacote(string $loteId, string $tmpPath, int $tamanho, string $originalName): void
    {
        $db = self::getDb();

        // Processa upload (retorna quantidade de imagens enviadas)
        $totalImagens = S3Helper::processarUpload($tmpPath, $originalName, $loteId);

        if ($totalImagens === 0) {
            throw new RuntimeException('Nenhuma imagem foi processada/enviada para o S3');
        }

        $prefix = preg_replace('/[^a-zA-Z0-9\-_]/', '', $loteId);
        $s3Prefix = "localhost:8026/imgs/{$prefix}/";
        $agora = date('Y-m-d H:i:s');

        $stmt = $db->prepare("
            INSERT INTO lotes_correcao (lote_id, s3_prefix, total_arquivos, status, criado_em, atualizado_em)
            VALUES (:lote_id, :s3_prefix, :total, 'uploaded', :criado_em, :atualizado_em)
            ON DUPLICATE KEY UPDATE
                s3_prefix = VALUES(s3_prefix),
                total_arquivos = VALUES(total_arquivos),
                status = 'uploaded',
                atualizado_em = VALUES(atualizado_em)
        ");

        $stmt->execute([
            ':lote_id' => $loteId,
            ':s3_prefix' => $s3Prefix,
            ':total' => $totalImagens,
            ':criado_em' => $agora,
            ':atualizado_em' => $agora,
        ]);
    }

    /**
     * Lista todos os lotes com resumo
     */
    public static function listarTodos(): array
    {
        $db = self::getDb();
        $stmt = $db->query("
            SELECT l.id, l.lote_id, l.s3_prefix, l.total_arquivos, l.status,
                   l.mensagem_erro, l.criado_em, l.atualizado_em,
                   r.corrigidas, r.defeituosas, r.repetidas, r.total AS total_paginas
            FROM lotes_correcao l
            LEFT JOIN resumo_lote r ON r.lote_id = l.lote_id
            ORDER BY l.criado_em DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca um lote por ID
     */
    public static function buscarPorId(string $loteId): ?array
    {
        $db = self::getDb();
        $stmt = $db->prepare("
            SELECT id, lote_id, s3_prefix, total_arquivos, status,
                   mensagem_erro, criado_em, atualizado_em, tempo_processamento_segundos
            FROM lotes_correcao
            WHERE lote_id = :lote_id
        ");
        $stmt->execute([':lote_id' => $loteId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
