# Design e Mapeamento de Campos

## 5. Especificação do Motor de Carteirinhas e do Servidor MCP

### 5.1. Contrato JSON do Mapa de Design (`mapa_campos_json` - Req 10, 11, 17, 17.1)

Cada design armazenado em `designs_carteirinha` possui uma estrutura padronizada que serve tanto ao renderizador WebP (`GD`/`Imagick`) quanto ao renderizador PDF vetorial:

```json
{
  "versao_schema": "1.0",
  "dimensoes": { "largura_px": 1011, "altura_px": 638, "dpi": 300, "padrao": "CR80" },
  "elementos": [
    {
      "id": "el_foto",
      "lado": "frente",
      "tipo": "foto_titular",
      "chave_variavel": "foto",
      "x": 68, "y": 140, "largura": 240, "altura": 320,
      "borda_raio": 16, "borda_espessura": 4, "borda_cor": "#FFFFFF",
      "placeholder_padrao": "assets/img/avatar_default.png"
    },
    {
      "id": "el_nome",
      "lado": "frente",
      "tipo": "texto_dinamico",
      "chave_variavel": "nome_completo",
      "rotulo_amigavel": "Nome do Titular",
      "obrigatorio": true,
      "x": 340, "y": 180, "largura_max": 620,
      "fonte_arquivo": "Inter-Bold.ttf", "tamanho_pt": 24,
      "cor_hex": "#0F172A", "alinhamento": "left", "transformacao": "uppercase",
      "auto_reduzir_fonte_se_longo": true
    },
    {
      "id": "el_qr",
      "lado": "verso",
      "tipo": "qrcode_validacao",
      "chave_variavel": "_sistema_url_validacao",
      "x": 760, "y": 380, "tamanho": 200,
      "margem_quiet_zone": 2
    }
  ]
}

```

### 5.2. Resolução do Mapeamento Duplo (Requisito 17.1)

O módulo `includes/motor_mapeamento.php` opera em dois modos selecionáveis:

* **Modo A — Mapeamento Fixo por Layout (`mapeamento_fixo_json`):** O Cliente salva no próprio layout a correspondência padrão (ex: `{"nome_completo": "nome", "documento_cpf": "cpf", "cargo": "dados_extras.cargo"}`). Ao selecionar 1 ou $N$ titulares já presentes no banco e clicar em **"Gerar Rápido"**, o sistema pula a tela de configuração e emite imediatamente.
* **Modo B — Mapeamento como Etapa da Geração (`mapa_de_para_usado_json`):** Ao importar um arquivo externo (CSV/XLSX/JSON) ou selecionar um lote de titulares para um layout específico, o passo 2 do *wizard* exibe os campos `chave_variavel` do design e permite vincular qualquer coluna do arquivo/banco, combinar colunas via template (ex: `{{Curso}} - {{Semestre}}º Sem`) ou definir um valor fixo para todo o lote (ex: `Validade: 12/2027`).


## Telas do MVP

**Editor de designs**

![Editor de designs](/telas/09-designs.png)

**Emissão com mapeamento de campos**

![Emissão com mapeamento de campos](/telas/10-emitir.png)

