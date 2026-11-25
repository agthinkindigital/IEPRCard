# Modo de hospedagem — guia funcional

Esta configuração global é exclusiva do superadmin e define se a instalação atende vários clientes pela internet ou uma única empresa no ambiente Local Host. O contrato técnico está em [ADR-0003](../adr/0003-multi-tenancy-por-linha.md).

A conexão PDO necessária para chegar ao login é configurada no assistente pré-login descrito em [Instalação inicial do banco](initial-setup-database.md). O menu **Instalação** também apresenta a configuração autenticada e a fonte ativa da conexão; senha nunca é exibida. A conexão do banco é infraestrutura da aplicação, independente da escolha de perfil abaixo.

## Escolher o modo

- **Hospedado:** use para sistema publicado em hospedagem compartilhada ou VPS. Informe o hostname da plataforma e o domínio base usado nos subdomínios. Cada Sede continua podendo cadastrar domínio próprio pela tela **Domínios próprios**, sujeita a prova DNS e HTTPS.
- **Local Host:** use quando usuários institucionais devem acessar uma empresa. A Sede é opcional durante a configuração; selecione uma ativa quando estiver pronta. As unidades vinculadas ficam disponíveis conforme permissões e conta do usuário. Hostnames não selecionam tenant neste modo. Links gerados usam `localhost` e só funcionam nesta máquina.
- O painel global permanece disponível para o superadmin antes de escolher o perfil ou criar uma Sede. Usuários institucionais só entram quando há Sede selecionada e sua conta ativa já foi provisionada.

## Salvar ou trocar

1. Escolha **Hospedado** ou **Local Host**.
2. Preencha apenas os campos exibidos pelo modo selecionado e confira o aviso de impacto.
3. Selecione **Salvar configuração** e confirme a alteração.
4. O sistema registra o administrador e o modo alterado. A configuração passa a valer nas próximas requisições.

Trocar o perfil não move nem apaga dados. No modo local, somente a Sede selecionada e suas unidades podem operar pelas contas institucionais; a administração global continua com o superadmin. No modo hospedado, acesso a dados continua exigindo tenant ativo, host válido quando aplicável e autorização vinculada à sessão; IDs enviados pelo navegador nunca concedem acesso.

## Estados e falhas

- Hostname inválido ou campos obrigatórios vazios: o sistema mantém o perfil atual e indica o campo a corrigir.
- Sede local inexistente ou inativa: não permite selecioná-la; salve o perfil sem Sede e configure-a depois.
- Conta institucional sem Sede selecionada ou sem provisionamento: não recebe contexto de tenant. O superadmin continua podendo entrar na administração global.
- Conta sem papel de superadmin, sessão expirada ou CSRF inválido: alteração negada.

Mockup e guia foram aprovados em 2026-11-22. A implementação está dividida no GitHub entre o serviço/roteamento [#86](https://github.com/agthinkindigital/IEPRCard/issues/86) e a tela superadmin [#87](https://github.com/agthinkindigital/IEPRCard/issues/87). O perfil Local Host não substitui a comprovação de DNS/TLS necessária para validar o modo Hospedado.
