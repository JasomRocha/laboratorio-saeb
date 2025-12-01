<?php

class FormPacoteCorrecao
{
    public ?string $loteId = null;
    public ?array $arquivo = null;

    public static array $mimeTypes = [
        'application/pdf',
        'application/zip',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private array $errors = [];

    public function validate(): bool
    {
        $this->errors = [];

        // Se não for ZIP, descrição é obrigatória
        if (empty($this->arquivo['name']) || !preg_match('/\.zip$/i', $this->arquivo['name'])) {
            if (empty($this->loteId)) {
                $this->errors['loteId'] = 'Descrição obrigatória para arquivos não-ZIP';
            }
        }

        if (empty($this->arquivo) || !isset($this->arquivo['tmp_name']) || $this->arquivo['error'] !== UPLOAD_ERR_OK) {
            $this->errors['arquivo'] = 'Nenhum arquivo enviado ou erro no upload';
        }

        return empty($this->errors);
    }

    public function hasErrors(?string $field = null): bool
    {
        return $field ? isset($this->errors[$field]) : !empty($this->errors);
    }

    public function getErrorSummary(): string
    {
        return implode('<br>', $this->errors);
    }
}
