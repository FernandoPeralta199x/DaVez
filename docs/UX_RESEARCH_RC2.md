# Pesquisa de UX e decisões do RC2

## Fontes oficiais consultadas

- WCAG 2.2: https://www.w3.org/TR/WCAG22/
- Novidades da WCAG 2.2: https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/
- MDN `prefers-reduced-motion`:
  https://developer.mozilla.org/docs/Web/CSS/@media/prefers-reduced-motion
- MDN `<input type="date">`:
  https://developer.mozilla.org/docs/Web/HTML/Reference/Elements/input/date
- MDN Service Worker lifecycle:
  https://developer.mozilla.org/docs/Web/API/Service_Worker_API/Using_Service_Workers
- OWASP Authorization Cheat Sheet:
  https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
- PHP session security:
  https://www.php.net/manual/en/session.security.ini.php
- MySQL 8.4 InnoDB locking:
  https://dev.mysql.com/doc/refman/8.4/en/innodb-locking.html

## Decisões aplicadas

1. Controles principais mantêm alvo de toque próximo ou superior a 44 px.
2. Foco de teclado permanece visível e não depende apenas de cor.
3. O layout não esconde ações essenciais em hover.
4. Animações respeitam `prefers-reduced-motion`.
5. Datas visíveis aceitam `MM/DD/YYYY`, mas o backend recebe `YYYY-MM-DD`.
6. O calendário nativo é aberto por botão com nome acessível.
7. PDFs são ações explícitas e ficam próximos do contexto que exportam.
8. A lista de relatórios usa tabela semântica, cabeçalho e região rolável.
9. O Service Worker recebe uma nova chave de cache para evitar CSS antigo.
10. A autorização continua validada no backend; a UI não é fronteira de segurança.

## Restrições

O painel administrativo ainda concentra HTML, CSS, JavaScript e backend em
`admin.php`. A camada RC2 foi aplicada progressivamente para reduzir risco de
regressão. A extração em controllers, views e assets independentes deve ocorrer
com testes de caracterização em mudanças menores.
