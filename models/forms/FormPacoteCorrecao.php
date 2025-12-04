<?php

namespace models\forms;
class FormPacoteCorrecao
{
    public ?string $nomeLote = null;
    public ?array $arquivo = null;
    public ?int $coletaId = null;
    public ?string $descricao = null;

    public static array $mimeTypes = [
        'application/pdf',
        'application/zip',
        'image/jpeg',
        'image/png',
        'image/gif',
    ];

    private array $errors = [];

    public function validate(): bool
    {
        $this->errors = [];

        // Valida coleta
        if (empty($this->coletaId)) {
            $this->errors['coletaId'] = 'Selecione uma coleta.';
        }

        // Valida lote
        if (empty($this->nomeLote)) {
            $this->errors['nomeLote'] = 'Informe o nome do lote.';
        }

        // Valida arquivo
        if (empty($this->arquivo) || !isset($this->arquivo['tmp_name'])) {
            $this->errors['arquivo'] = 'Nenhum arquivo foi enviado.';
        } elseif ($this->arquivo['error'] !== UPLOAD_ERR_OK) {
            $this->errors['arquivo'] = 'Erro no upload do arquivo.';
        }

        return empty($this->errors);
    }

    public function hasErrors(?string $attribute = null): bool
    {
        if ($attribute === null) {
            return !empty($this->errors);
        }
        return isset($this->errors[$attribute]);
    }

    public function getErrorSummary(): string
    {
        if (empty($this->errors)) {
            return '';
        }
        return implode('<br>', $this->errors);
    }
}
