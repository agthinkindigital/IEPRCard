# Modo de hospedagem — guia funcional

Esta configuração global é exclusiva do superadmin e define se a instalação atende vários clientes pela internet ou uma única empresa no ambiente Local Host. O contrato técnico está em [ADR-0003](../adr/0003-multi-tenancy-por-linha.md).

## Escolher o modo

- **Hospedado:** use para sistema publicado em hospedagem compartilhada ou VPS. Informe o hostname da plataforma e o domínio base usado nos subdomínios. Cada Sede continua podendo cadastrar domínio próprio pela tela **Domínios próprios**, sujeita a prova DNS e HTTPS.
- **Local Host:** use quando esta instalação deve atender apenas uma empresa. Selecione a Sede ativa; as unidades vinculadas continuam disponíveis para usuários autorizados. Os hostnames não selecionam tenant neste modo. Links gerados usam `localhost` e só funcionam nesta máquina.
- Se ainda não houver perfil, o superadmin precisa escolher e salvar um antes de liberar as áreas administrativas e de tenant.

## Salvar ou trocar

1. Escolha **Hospedado** ou **Local Host**.
2. Preencha apenas os campos exibidos pelo modo selecionado e confira o aviso de impacto.
3. Selecione **Salvar configuração** e confirme a alteração.
4. O sistema registra o administrador e o modo alterado. A configuração passa a valer nas próximas requisições.

Trocar o perfil não move nem apaga dados. No modo local, somente a Sede selecionada e suas unidades podem operar por esta instalação. No modo hospedado, acesso a dados continua exigindo tenant ativo, host válido quando aplicável e autorização vinculada à sessão; IDs enviados pelo navegador nunca concedem acesso.

## Estados e falhas

- Hostname inválido ou campos obrigatórios vazios: o sistema mantém o perfil atual e indica o campo a corrigir.
- Sede local inexistente ou inativa: não permite salvar Local Host; ative ou configure a Sede antes.
- Acesso sem perfil: somente o setup necessário ao superadmin fica disponível.
- Conta sem papel de superadmin, sessão expirada ou CSRF inválido: alteração negada.

Mockup e guia foram aprovados em 2026-11-22. A implementação está dividida no GitHub entre o serviço/roteamento [#86](https://github.com/agthinkindigital/IEPRCard/issues/86) e a tela superadmin [#87](https://github.com/agthinkindigital/IEPRCard/issues/87). O perfil Local Host não substitui a comprovação de DNS/TLS necessária para validar o modo Hospedado.
