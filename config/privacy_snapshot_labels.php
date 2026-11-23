<?php
declare(strict_types=1);

/** @return array<string,list<string>> */
function privacySnapshotLabelFields(): array
{
    return [
        'planos' => ['nome', 'descricao'],
        'perfis_acesso' => ['nome_perfil'],
        'designs_carteirinha' => ['nome_layout', 'mapa_campos_json', 'mapeamento_fixo_json'],
        'categorias_titular' => ['nome_categoria'],
        'destinos_backup' => ['nome'],
    ];
}

/** Reject structured identifiers in labels whose contract requires generic wording. */
function privacySnapshotAssertNoPiiLabel(string $table, array $row): void
{
    foreach (privacySnapshotLabelFields()[$table] ?? [] as $field) {
        $value = $row[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            continue;
        }
        if (privacySnapshotLabelContainsStructuredPii($value)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_LABEL_PII');
        }
    }
}

function privacySnapshotLabelContainsStructuredPii(string $value): bool
{
    if (preg_match('/(?<![A-Z0-9._%+-])[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}(?![A-Z0-9.-])/iD', $value) === 1
        || preg_match('/(?<!\d)\d{3}[.\s]?\d{3}[.\s]?\d{3}[-\s]?\d{2}(?!\d)/', $value) === 1
        || preg_match('/(?<!\d)\+?\d[\d ().-]{8,}\d(?!\d)/', $value) === 1) {
        return true;
    }
    return false;
}
