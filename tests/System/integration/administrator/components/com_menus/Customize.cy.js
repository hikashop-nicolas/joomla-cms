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
});
