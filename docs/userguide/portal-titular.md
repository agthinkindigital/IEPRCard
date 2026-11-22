# Portal do titular e validação da carteirinha — guia funcional

O portal permite que o titular consulte somente as carteirinhas digitais emitidas para ele. A validação por QR é pública e confirma a situação da carteirinha sem mostrar CPF. As regras de acesso, retenção e privacidade estão em [`docs/01-regras-de-negocio.md`](../01-regras-de-negocio.md); o fluxo de referência está no [SAD](../architecture/SAD.md#45-sequência--f3f4-acesso-do-titular-e-validação-pública).

## Entrar no portal

1. Abra o endereço da organização ou o link individual recebido dela.
2. Se abrir a página de acesso, informe CPF e data de nascimento. O link individual com token único pode abrir diretamente a sessão prevista para a carteirinha.
3. Se o acesso falhar, confira os dados e solicite à própria organização um novo link ou a revisão do cadastro. A tela não informa se um CPF está cadastrado.
4. Ao autenticar, você vai diretamente para a área da carteirinha. Não há cadastro público nem acesso a funções administrativas.

## Consultar a carteirinha

1. Confira o nome e a unidade emissora exibidos no cabeçalho.
2. Use **Frente** e **Verso** para alternar os lados. No celular, os mesmos controles ficam abaixo da imagem.
3. Se houver mais de uma emissão ativa disponível, escolha a carteirinha pelo seletor. Se houver apenas uma, o seletor não aparece.
4. Se **Baixar PDF** aparecer, selecione a ação para obter o arquivo da emissão ativa. A opção só aparece quando a Sede habilitou o recurso e, para uma unidade, autorizou explicitamente `carteirinha.pdf`. Se ela não aparecer, peça à Sede que confira a configuração; acessar o endereço do arquivo diretamente também não libera o download.
5. Para confirmar a autenticidade, abra a câmera do celular e leia o QR da carteirinha. A página pública informa somente o estado (`ativa`, `suspensa`, `vencida`, `revogada` ou `inválida`); não exibe nome, foto, CPF, unidade ou validade.

## Estados e problemas

- **Ativa:** a página de validação informa somente que a carteirinha está ativa.
- **Suspensa, vencida ou revogada:** a página informa que a carteirinha não está ativa e orienta a consultar a organização emissora.
- **Código inválido:** a página informa que não foi possível validar o código e permite tentar outro QR. Ela não confirma se existe um titular com aqueles dados.
- **Acesso indisponível:** se o link expirou, o acesso foi desabilitado ou a carteira não está disponível, solicite à organização um novo link ou a regularização do cadastro.
- **PDF indisponível:** a ação não aparece quando a Sede não habilitou o recurso, a unidade não recebeu delegação ou a carteirinha não está ativa. A autorização é conferida novamente ao solicitar o arquivo.

O sistema não solicita dados de pagamento. O portal não altera dados cadastrais, não permite editar a carteirinha e não mostra informações de outras pessoas.
