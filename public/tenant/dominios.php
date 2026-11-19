<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/domain_onboarding.php';

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$pdo = dbConnection();
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$context = tenantResolverResolve($pdo, $host);
if ($context === null || $context->tipo !== 'cliente' || $context->tenantId === null) {
    http_response_code(404);
    exit('Página não encontrada.');
}
if (!tenantAuthStartSession()) {
    http_response_code(401);
    exit('Acesso restrito à sessão da instituição.');
}
$identity = tenantAuthCurrentUser($pdo, $context);
if ($identity === null) {
    http_response_code(401);
    exit('Acesso restrito à sessão da instituição.');
}
header('Cache-Control: no-store, private');
if ($identity['papel_sistema'] !== 'titular_cliente' || $identity['subcliente_id'] !== null) {
    http_response_code(403);
    exit('Esta área é exclusiva do Cliente Master da Sede.');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if (!tenantAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'create') {
        $hostname = is_string($_POST['hostname'] ?? null) ? $_POST['hostname'] : '';
        try {
            $created = domainOnboardingCreate($pdo, $context, $identity, $hostname, []);
            $_SESSION['tenant_domain_challenges'] ??= [];
            $_SESSION['tenant_domain_challenges'][(string) $created['id']] = [
                'token' => $created['token'],
                'txt_name' => $created['txt_name'],
                'txt_value' => $created['txt_value'],
                'hostname' => $created['hostname'],
            ];
            $_SESSION['tenant_domain_challenge_flash'] = (string) $created['id'];
            $_SESSION['tenant_domain_notice'] = 'Domínio cadastrado. Publique o registro TXT para continuar.';
        } catch (DomainException | PDOException) {
            $_SESSION['tenant_domain_notice'] = 'Este domínio não está disponível. Confira o nome e tente novamente.';
        }
        tenantDomainsRedirect();
    }

    if ($action === 'verify') {
        $domainId = filter_var($_POST['domain_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $challenge = $domainId === false
            ? null
            : ($_SESSION['tenant_domain_challenges'][(string) $domainId] ?? null);
        $ownedDomain = null;
        if ($domainId !== false) {
            $query = $pdo->prepare(
                "SELECT id FROM dominios_tenant WHERE id = :id AND tenant_id = :tenant_id AND estado = 'pendente_verificacao' LIMIT 1"
            );
            $query->execute(['id' => $domainId, 'tenant_id' => $context->tenantId]);
            $ownedDomain = $query->fetchColumn();
        }

        if (is_array($challenge) && is_string($challenge['token'] ?? null)
            && $ownedDomain !== false && $ownedDomain !== null) {
            try {
                $verified = domainOnboardingVerify(
                    $pdo,
                    (int) $domainId,
                    $challenge['token'],
                    'domainOnboardingLookupTxt'
                );
            } catch (Throwable) {
                $verified = false;
            }
            if ($verified) {
                unset($_SESSION['tenant_domain_challenges'][(string) $domainId]);
                $_SESSION['tenant_domain_notice'] = 'Prova DNS confirmada. O próximo passo é configurar HTTPS no host; o domínio permanece em TLS pendente.';
            } else {
                $_SESSION['tenant_domain_notice'] = 'O registro TXT ainda não foi confirmado. Confira nome e valor e tente novamente.';
            }
        } else {
            $_SESSION['tenant_domain_notice'] = 'O desafio não está mais disponível nesta sessão. A recuperação segura ainda não está disponível; entre em contato com o suporte.';
        }
        tenantDomainsRedirect();
    }

    http_response_code(400);
    exit('Ação inválida.');
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$tenantId = $context->tenantId;
$statement = $pdo->prepare(
    'SELECT id, hostname, estado, criado_em, verificado_em, tls_validado_em '
    . 'FROM dominios_tenant WHERE tenant_id = :tenant_id ORDER BY id DESC'
);
$statement->execute(['tenant_id' => $tenantId]);
$domains = $statement->fetchAll(PDO::FETCH_ASSOC);
$notice = is_string($_SESSION['tenant_domain_notice'] ?? null) ? $_SESSION['tenant_domain_notice'] : '';
unset($_SESSION['tenant_domain_notice']);
$challengeFlashId = is_string($_SESSION['tenant_domain_challenge_flash'] ?? null)
    ? $_SESSION['tenant_domain_challenge_flash'] : null;
unset($_SESSION['tenant_domain_challenge_flash']);
$challenge = $challengeFlashId !== null
    ? ($_SESSION['tenant_domain_challenges'][$challengeFlashId] ?? null)
    : null;
$csrfToken = tenantAuthCsrfToken($_SESSION);
$organization = (string) ($context->branding['nome_organizacao'] ?? 'Instituição');

function tenantDomainsEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tenantDomainsState(array $domain): array
{
    $state = (string) ($domain['estado'] ?? '');
    return match ($state) {
        'pendente_verificacao' => ['Aguardando DNS', 'O registro TXT ainda precisa ser localizado.'],
        'tls_pendente' => ['TLS pendente', 'A propriedade foi confirmada; falta ativar HTTPS no host.'],
        'ativo' => ['Ativo', 'DNS verificado e HTTPS validado.'],
        'revogado', 'removido' => ['Removido', 'Este host não direciona visitantes para a instituição.'],
        default => ['Em análise', 'Estado registrado pelo sistema.'],
    };
}

function tenantDomainsRedirect(): never
{
    session_write_close();
    header('Location: /tenant/dominios.php', true, 303);
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Domínios próprios</title>
    <link rel="stylesheet" href="/assets/tenant-admin.css">
    <script src="/assets/tenant-domains.js" defer></script>
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="/tenant/dominios.php"><span class="brand-mark" aria-hidden="true">+</span><strong>IEPRCard</strong></a>
    <p class="nav-heading">INSTITUIÇÃO</p>
    <p class="organization"><?= tenantDomainsEscape($organization) ?></p>
    <nav aria-label="Navegação da instituição"><a href="/tenant/unidades.php">Unidades</a><a href="/tenant/equipe.php">Equipe</a><a href="/tenant/cotas.php">Cotas</a><a class="active" aria-current="page" href="/tenant/dominios.php">Domínios próprios</a></nav>
    <p class="sidebar-note">Área do Cliente Master da Sede</p>
</aside>
<header class="topbar"><p>Painel <span aria-hidden="true">/</span> Configurações <span aria-hidden="true">/</span> <strong>Domínios próprios</strong></p></header>
<main class="content">
    <div class="page-heading">
        <div><h1>Domínios próprios</h1><p>Conecte o domínio da sua instituição à carteirinha digital.</p></div>
        <form class="add-domain" method="post">
            <input type="hidden" name="csrf_token" value="<?= tenantDomainsEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="create">
            <label for="hostname">Adicionar domínio</label>
            <div><input id="hostname" name="hostname" type="text" inputmode="url" autocomplete="url" maxlength="253" placeholder="instituto-exemplo.org.br" required><button type="submit">Adicionar domínio</button></div>
            <small>Informe somente o hostname, sem protocolo, caminho ou porta.</small>
        </form>
    </div>
    <section class="info-banner" aria-label="Informação sobre ativação">
        <strong>O domínio só direciona visitantes após a prova DNS e a ativação do HTTPS.</strong>
        <p>Hosts em verificação ou com TLS pendente não abrem a conta da instituição.</p>
    </section>
    <?php if ($notice !== ''): ?><p class="notice" role="status" aria-live="polite"><?= tenantDomainsEscape($notice) ?></p><?php endif; ?>
    <div class="domain-layout">
        <section class="panel domain-list" aria-labelledby="domains-title">
            <h2 id="domains-title">Seus domínios</h2>
            <p class="subtle">Cada domínio precisa ser verificado e ter HTTPS ativo.</p>
            <?php if ($domains === []): ?>
                <p class="empty-state">Nenhum domínio próprio cadastrado.</p>
            <?php else: ?>
                <div class="table-scroll" role="region" aria-label="Lista de domínios" tabindex="0">
                    <table>
                        <thead><tr><th scope="col">Domínio</th><th scope="col">Situação</th><th scope="col">Ação</th></tr></thead>
                        <tbody>
                        <?php foreach ($domains as $domain): ?>
                            <?php [$label, $description] = tenantDomainsState($domain); ?>
                            <tr>
                                <td><strong><?= tenantDomainsEscape((string) $domain['hostname']) ?></strong><small><?= tenantDomainsEscape((string) ($domain['criado_em'] ?? '')) ?></small></td>
                                <td><span class="status status-<?= tenantDomainsEscape((string) $domain['estado']) ?>"><?= tenantDomainsEscape($label) ?></span><small><?= tenantDomainsEscape($description) ?></small></td>
                                <td><?php if ($domain['estado'] === 'pendente_verificacao' && is_array($_SESSION['tenant_domain_challenges'][(string) $domain['id']] ?? null)): ?>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= tenantDomainsEscape($csrfToken) ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="domain_id" value="<?= (int) $domain['id'] ?>"><button class="secondary-button" type="submit">Conferir registro DNS</button></form>
                                <?php else: ?><span class="subtle">Detalhes</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <aside class="state-guide" aria-label="Como ler os estados">
                <h3>Como ler os estados</h3>
                <p><strong>Falha na prova DNS:</strong> o TXT não foi localizado ou não corresponde ao desafio.</p>
                <p><strong>TLS pendente:</strong> a propriedade foi confirmada; falta ativar e validar HTTPS.</p>
                <p><strong>Ativo:</strong> prova DNS e HTTPS foram confirmados.</p>
            </aside>
            <p class="backend-note">A validação automática de TLS, remoção e recuperação do desafio dependem de serviços de backend ainda não disponíveis.</p>
        </section>
        <section class="panel challenge-panel" aria-labelledby="challenge-title">
            <h2 id="challenge-title">Comprovar domínio</h2>
            <?php if (is_array($challenge)): ?>
                <p class="challenge-host"><?= tenantDomainsEscape((string) $challenge['hostname']) ?></p>
                <ol class="steps"><li class="done">Domínio cadastrado</li><li class="current">Prova DNS</li><li>Ativar HTTPS (TLS)</li><li>Domínio ativo</li></ol>
                <h3>Crie este registro no DNS</h3>
                <p>Entre no painel onde administra o domínio e adicione:</p>
                <div class="copy-field"><h3 id="txt-name-label">Nome / host</h3><button type="button" class="copy-button" data-copy-target="txt-name" aria-label="Copiar nome do registro TXT">Copiar</button></div><pre id="txt-name" aria-labelledby="txt-name-label"><?= tenantDomainsEscape((string) $challenge['txt_name']) ?></pre>
                <div class="copy-field"><h3 id="txt-value-label">Valor <span>(exibido uma única vez)</span></h3><button type="button" class="copy-button" data-copy-target="txt-value" aria-label="Copiar valor do registro TXT">Copiar</button></div><pre id="txt-value" aria-labelledby="txt-value-label"><?= tenantDomainsEscape((string) $challenge['txt_value']) ?></pre>
                <p class="copy-status" role="status" aria-live="polite" data-copy-status></p>
                <p class="challenge-warning">Mantenha esta etapa aberta até publicar o TXT. Se sair ou a sessão expirar, será necessário iniciar uma nova prova.</p>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= tenantDomainsEscape($csrfToken) ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="domain_id" value="<?= (int) $challengeFlashId ?>"><button type="submit">Conferir registro DNS</button></form>
            <?php else: ?>
                <p class="empty-state">Selecione um domínio aguardando DNS para conferir sua prova.</p>
                <p class="backend-note">O desafio TXT é exibido somente logo após o cadastro. Guarde o nome e o valor antes de sair desta etapa.</p>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
