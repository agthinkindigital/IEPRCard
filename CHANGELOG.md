# Changelog — IEPRCard

## [Unreleased]

### Adicionado

- [E06.1 #40] Métricas operacionais diárias e agregadas dos lotes, sem identificadores pessoais, com atualização concorrente segura em SQLite, MySQL e MariaDB.
- [E06.2 #41] Previsão de pico de demanda e lote inicial recomendado pelo P95 nearest-rank sobre dias UTC completos; histórico abaixo de sete dias é sinalizado sem recomendação numérica.
- [E06.3 #42] Configuração operacional versionada como pendente e ativada no início do worker, com snapshot imutável, isolamento por ambiente e concorrência coberta por SQLite, MySQL 8.4 e MariaDB 10.11.

