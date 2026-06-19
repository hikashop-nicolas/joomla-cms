describe('Test that the com_templates Customize host', () => {
  beforeEach(() => cy.doAdministratorLogin());

  // The default home site template style (Customize is launched per style from Templates: Styles).
  const styleQuery = "SELECT id FROM #__template_styles WHERE client_id = 0 AND home = '1' LIMIT 1";

  it('opens for a template style and loads the engine + layout plugin', () => {
    cy.task('queryDB', styleQuery).then((rows) => {
      cy.visit(`administrator/index.php?option=com_templates&view=customize&id=${rows[0].id}`);

      // The host view renders, with the toolbar title and the live-preview iframe.
      cy.get('h1.page-title').should('contain.text', 'Customize');
      cy.get('#customize-frame').should('exist');

      // The engine module loads and exposes the API on the parent window.
      cy.window().its('JoomlaCustomize').should('exist');

      // The layout plugin registered its toolbar buttons (Move + Split on a layout block) ...
      cy.window().its('JoomlaCustomize').invoke('getButtons', 'layout-block').then((buttons) => {
        const ids = buttons.map((button) => button.id);
        expect(ids).to.include('move');
        expect(ids).to.include('split');
      });

      // ... and its split-cell area type (draggable, so cells reorder among themselves).
      cy.window().its('JoomlaCustomize').invoke('getAreaType', 'split-cell')
        .its('draggable').should('eq', true);
    });
  });

  it('reads the template positions through the templateAction endpoint', () => {
    cy.task('queryDB', styleQuery).then((rows) => {
      cy.visit(`administrator/index.php?option=com_templates&view=customize&id=${rows[0].id}`);
    });

    cy.window().its('JoomlaCustomize').should('exist');

    cy.window()
      .then((win) => win.JoomlaCustomize.templateAction('listPositions', {}))
      .then((res) => {
        expect(res).to.have.property('success', true);
        expect(res).to.have.property('grids');
      });
  });

  it('splits a position (distributing its modules), then unsplits to restore them', () => {
    cy.task('queryDB', styleQuery).then((rows) => {
      cy.visit(`administrator/index.php?option=com_templates&view=customize&id=${rows[0].id}`);
    });

    cy.window().its('JoomlaCustomize').should('exist');

    // Two modules in top-a so a two-column split puts one in each cell.
    cy.window().then((win) => win.JoomlaCustomize.callAction('position', 'add', { title: 'CyTest A', module: 'mod_custom', position: 'top-a' }))
      .then((res) => expect(res).to.have.property('success', true));
    cy.window().then((win) => win.JoomlaCustomize.callAction('position', 'add', { title: 'CyTest B', module: 'mod_custom', position: 'top-a' }))
      .then((res) => expect(res).to.have.property('success', true));

    // Split top-a -> [top-a, top-a-2]; the two modules end up one per cell.
    cy.window().then((win) => win.JoomlaCustomize.templateAction('splitPosition', { block: 'top-a', layout: 'cols-2' }))
      .then((res) => {
        expect(res).to.have.property('success', true);
        expect(res.positions).to.deep.equal(['top-a', 'top-a-2']);

        return cy.task('queryDB', "SELECT position FROM #__modules WHERE title IN ('CyTest A', 'CyTest B') AND client_id = 0 ORDER BY ordering");
      })
      .then((rows) => {
        expect(rows.map((row) => row.position).sort()).to.deep.equal(['top-a', 'top-a-2']);
      });

    // Unsplit folds both modules back into top-a and drops the split.
    cy.window().then((win) => win.JoomlaCustomize.templateAction('unsplitPosition', { block: 'top-a' }))
      .then((res) => {
        expect(res).to.have.property('success', true);

        return cy.task('queryDB', "SELECT position FROM #__modules WHERE title IN ('CyTest A', 'CyTest B') AND client_id = 0");
      })
      .then((rows) => {
        rows.forEach((row) => expect(row.position).to.equal('top-a'));
      });

    // Remove the test modules.
    cy.task('queryDB', "DELETE mm FROM #__modules_menu mm JOIN #__modules m ON m.id = mm.moduleid WHERE m.title IN ('CyTest A', 'CyTest B')");
    cy.task('queryDB', "DELETE FROM #__modules WHERE title IN ('CyTest A', 'CyTest B')");
  });
});
