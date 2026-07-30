# Compatibilidade móvel — Android e iOS

## Status

O frontend e a PWA possuem validação automatizada local, mas a compatibilidade
completa ainda depende da execução deste checklist em aparelhos físicos.

Não declarar o sistema pronto para produção móvel enquanto os cenários abaixo
não estiverem aprovados.

## Pré-requisitos do ambiente

- HTTPS válido em homologação e produção.
- `manifest.json` servido como JSON sem redirecionamento.
- `service-worker.js` servido na raiz do mesmo domínio da aplicação.
- geolocalização permitida por `Permissions-Policy` e pelo navegador.
- cookies de sessão com atributos compatíveis com HTTPS e o mesmo domínio.
- ícones PNG reais de 192×192 e 512×512.

## Matriz mínima

| Plataforma | Navegador | Modo | Status |
| --- | --- | --- | --- |
| Android físico | Chrome estável atual | navegador | Pendente |
| Android físico | Chrome estável atual | PWA instalada | Pendente |
| iPhone físico | Safari atual | navegador | Pendente |
| iPhone físico | Web App da Tela de Início | standalone | Pendente |
| iPad físico | Safari atual | retrato e paisagem | Pendente |
| iPad físico | Web App da Tela de Início | standalone | Pendente |

## Instalação

- Android oferece a instalação e usa o ícone 512×512 sem corte indevido.
- iPhone e iPad exibem “Adicionar à Tela de Início” pelo menu Compartilhar.
- o nome instalado é `DaVez` nas duas plataformas.
- o aplicativo abre dentro do escopo correto e sem barra de endereço quando
  instalado.
- reinstalação e atualização não preservam uma interface obsoleta.

## Interface

- não existe rolagem horizontal em 320 px de largura.
- notch, Dynamic Island, cantos arredondados e indicador inferior não cobrem
  controles.
- o teclado não amplia os campos de nome e código.
- retrato e paisagem preservam conteúdo, botões e modais.
- temas claro e escuro mantêm contraste e persistem após reabrir o aplicativo.
- logo, Administração e seletor de tema não colapsam durante o carregamento.

## Fluxos operacionais

- permitir geolocalização conclui o check-in.
- negar geolocalização apresenta erro compreensível e permite nova tentativa.
- precisão insuficiente não registra check-in.
- recuperação de acesso restaura a sessão no mesmo dispositivo.
- encerramento de sessão remove o acesso local.
- atualização da fila continua após bloquear e desbloquear a tela.
- voltar do histórico ou do seletor de aplicativos atualiza o estado exibido.

## Rede, offline e atualização

- a home abre offline depois do primeiro carregamento.
- chamadas PHP nunca são respondidas pelo cache.
- `/admin.php` offline não exibe a home pública sob a URL administrativa.
- uma nova versão mostra “Atualizar agora”.
- a troca do Service Worker acontece somente depois da confirmação.
- após atualizar, a página recarrega uma vez e usa o novo cache.
- perda e retorno de conexão atualizam o estado sem recarregamento manual.

## Critério de aprovação

Cada linha da matriz precisa ter evidência com:

1. modelo do aparelho;
2. versão do sistema;
3. versão do navegador;
4. data do teste;
5. resultado de instalação, geolocalização, sessão, offline e atualização;
6. captura de tela apenas quando não contiver dados pessoais.

Qualquer falha em instalação, autenticação, geolocalização, atualização,
isolamento de cache ou acesso administrativo bloqueia a liberação móvel.

## Referências oficiais

- Chrome: https://developer.chrome.com/docs/lighthouse/pwa/installable-manifest
- Chrome: https://developer.chrome.com/blog/improvements-to-web-app-updates
- Apple: https://developer.apple.com/videos/play/wwdc2021/10029/
- WebKit: https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/
