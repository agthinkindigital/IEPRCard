# Servidor MCP

O MCP estará disponível desde o primeiro lançamento, inclusive em produção, por um endpoint remoto Streamable HTTP stateless: uma chamada por POST, sem sessão em memória nem processo residente. Cada requisição autentica a chave, aplica escopo/tenant e registra auditoria sem PII. Em produção, o registry expõe apenas ferramentas de leitura; alterações de design exigem aprovação humana e patches de código não são registrados. Tools demoradas devolvem um `job_id` e o agente consulta o estado em outra chamada. STDIO é opcional para desenvolvimento local. Ver `docs/adr/0009-mcp-escopos-e-patch-seguro.md`.

### 5.3. Especificação das Tools do MCP Server (Requisito 18)

O arquivo `mcp/server.php` expõe via protocolo **Model Context Protocol (JSON-RPC 2.0)** as ferramentas abaixo para que agentes autônomos possam operar, auditar e evoluir o sistema:

| Nome da Tool MCP | Parâmetros de Entrada | Ação Executada pelo Sistema |
| --- | --- | --- |
| `mcp.system.status` | `{}` | Retorna driver de banco ativo (`sqlite` ou `mysql`), status das extensões (`gd`, `imagick`, `zip`, `curl`), espaço em disco e resumo de saúde. |
| `mcp.telemetry.query` | `{"escopo": "admin|cliente|sub", "tenant_id": int, "periodo": "7d|30d|mes_atual"}` | Retorna o JSON estruturado de telemetria (usuários, carteirinhas PDF/WebP geradas, lotes, conversas WhatsApp e receitas). |
| `mcp.db.inspect` | `{"tabela": string}` | Lista todas as tabelas e colunas atuais usando a introspecção dual SQLite/MySQL e aponta divergências de schema. |
| `mcp.design.inspect_and_map` | `{"design_id": int, "novo_mapa_json": object, "gerar_preview": bool}` | Atualiza as coordenadas do layout de uma carteirinha e gera uma imagem WebP de teste com dados fictícios para o agente validar visualmente o alinhamento. |
| `mcp.batch.dry_run` | `{"lote_id": int, "limite_amostra": 3}` | Simula a importação e renderização das primeiras $N$ carteirinhas de um lote para detectar erros de fonte, foto ausente ou estouro de texto. |
| `mcp.code.safe_patch` | `{"arquivo_relativo": string, "conteudo_novo": string, "motivo": string}` | Cria backup instantâneo em `storage/backups_mcp/`, valida a sintaxe com `php -l`, aplica a alteração e executa a suíte de testes TDD correspondente. Se o teste falhar, faz *rollback* automático. |

---
