describe('Test that the com_menus Customize host', () => {
  beforeEach(() => cy.doAdministratorLogin());

  it('opens for a menu item and loads the ES module engine and plugins', () => {
    cy.task('queryDB', "SELECT id FROM #__menu WHERE home = 1 AND client_id = 0 AND published = 1 LIMIT 1")
      .then((rows) => {
        const id = rows[0].id;

        cy.visit(`administrator/index.php?option=com_menus&view=customize&id=${id}`);

        // The host view renders, with the toolbar title and the live-preview iframe.
        cy.get('h1.page-title').should('contain.text', 'Customize');
        cy.get('#customize-frame').should('exist');

        // The engine module loads and exposes the API on the parent window.
        cy.window().its('JoomlaCustomize').should('exist');
        cy.window().its('JoomlaCustomize.registerAreaType').should('be.a', 'function');

        // The customize plugins are ES modules that import the shared api; the module plugin
        // registering its buttons proves that wiring resolved at runtime.
        cy.window().its('JoomlaCustomize').invoke('getButtons', 'module')
          .should('have.length.greaterThan', 0);
      });
  });

  it('persists an inline content edit made through the engine API', () => {
    const marker = 'Edited by the Customize system test';
    let articleId;

    // A content area edit, end to end: the engine calls the content plugin's save action over
    // com_ajax, which saves through the article model. (The article is removed by cleanupDB.)
    cy.db_createArticle({ title: 'Customize system test article', introtext: '<p>Original intro</p>' })
      .then((article) => {
        articleId = article.id;
      });

    cy.task('queryDB', "SELECT id FROM #__menu WHERE home = 1 AND client_id = 0 AND published = 1 LIMIT 1")
      .then((rows) => {
        cy.visit(`administrator/index.php?option=com_menus&view=customize&id=${rows[0].id}`);
      });

    cy.window().its('JoomlaCustomize').should('exist');

    cy.window()
      .then((win) => win.JoomlaCustomize.callAction('content', 'save', {
        id: articleId,
        field: 'introtext',
        html: `<p>${marker}</p>`,
      }))
      .then((res) => {
        expect(res).to.have.property('success', true);

        return cy.task('queryDB', `SELECT introtext FROM #__content WHERE id = ${articleId}`);
      })
      .then((rows) => {
        expect(rows[0].introtext).to.contain(marker);
      });
  });
});
