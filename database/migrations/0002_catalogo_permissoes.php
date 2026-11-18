<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $permissions = [
        'titular.ver' => 'Visualizar titulares',
        'titular.criar' => 'Cadastrar titulares',
        'titular.editar' => 'Editar titulares',
        'titular.excluir' => 'Excluir titulares',
        'titular.importar' => 'Importar titulares',
        'titular.exportar' => 'Exportar titulares',
        'carteirinha.gerar' => 'Gerar carteirinhas',
        'carteirinha.lote' => 'Gerar carteirinhas em lote',
        'carteirinha.reimprimir' => 'Reimprimir carteirinhas',
        'carteirinha.suspender' => 'Suspender carteirinhas',
        'carteirinha.liberar_acesso' => 'Liberar acesso do titular',
        'design.ver' => 'Visualizar designs',
        'design.criar' => 'Criar designs',
        'design.editar' => 'Editar designs',
        'design.mapear' => 'Mapear campos do design',
        'design.publicar' => 'Publicar designs',
        'subcliente.ver' => 'Visualizar unidades',
        'subcliente.criar' => 'Criar unidades',
        'subcliente.editar' => 'Editar unidades',
        'subcliente.desativar' => 'Desativar unidades',
        'usuario.ver' => 'Visualizar equipe',
        'usuario.criar' => 'Criar acessos de equipe',
        'usuario.permissoes' => 'Gerenciar permissões da equipe',
        'cota.ver' => 'Visualizar cotas',
        'cota.distribuir' => 'Distribuir cotas',
        'financeiro.ver' => 'Visualizar plano e situação financeira',
        'financeiro.plano' => 'Gerenciar plano da conta',
        'telemetria.unidade' => 'Visualizar telemetria da unidade',
        'telemetria.rede' => 'Visualizar telemetria da rede',
        'admin.clientes' => 'Gerenciar contas de clientes',
        'admin.planos' => 'Gerenciar planos',
        'admin.mcp.tokens' => 'Gerenciar chaves MCP',
        'admin.auditoria.global' => 'Visualizar auditoria global',
        'admin.telemetria' => 'Visualizar telemetria global',
    ];
    $find = $pdo->prepare('SELECT id FROM permissoes WHERE chave = :key LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO permissoes (chave, descricao) VALUES (:key, :description)');
    foreach ($permissions as $key => $description) {
        $find->execute(['key' => $key]);
        if ($find->fetchColumn() === false) {
            $insert->execute(['key' => $key, 'description' => $description]);
        }
    }
};
