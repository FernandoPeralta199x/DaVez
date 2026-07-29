# Política de segurança

## Situação do projeto

O DaVez está em processo de organização e correção. O baseline inicial não deve ser tratado como release pronta para produção.

## Relato de vulnerabilidades

Não publique tokens, credenciais, dados pessoais, logs, relatórios, coordenadas ou provas de conceito sensíveis em issues públicas.

Entre em contato de forma privada com o mantenedor pelo perfil associado ao repositório e informe:

- versão ou commit afetado;
- componente e rota envolvidos;
- impacto esperado;
- passos mínimos de reprodução, sem dados reais;
- mitigação sugerida, quando disponível.

## Dados que nunca devem ser versionados

- `config.php` preenchido;
- arquivos `.env`;
- credenciais administrativas ou de banco;
- tokens e cookies;
- logs de runtime;
- relatórios operacionais;
- dumps ou backups de banco;
- dados pessoais e coordenadas reais.

## Requisitos mínimos antes de produção

- autenticação e autorização individuais;
- proteção CSRF;
- gestão externa de secrets;
- logs sanitizados e fora do webroot;
- isolamento e validação dos dados;
- schema e migrations versionados;
- testes automatizados;
- backup, restore e rollback verificados;
- monitoramento e documentação operacional.
