/*
 * Tine 2.0
 * 
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schüle <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2011 Metaways Infosystems GmbH (http://www.metaways.de)
 */
Ext.ns('Tine.Projects.Model');

/**
 * @namespace   Tine.Projects.Model
 * @class       Tine.Projects.Model.Project
 * @extends     Tine.Tinebase.data.Record
 * Example record definition
 * 
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 */
Tine.Projects.Model.Project = Tine.Tinebase.data.Record.create(Tine.Tinebase.Model.genericFields.concat([
    { name: 'id' },
    { name: 'title' },
    { name: 'number' },
    { name: 'description' },
    { name: 'status' },
    { name: 'attendee' },
    // tine 2.0 notes + tags
    { name: 'notes'},
    { name: 'tags' }
]), {
    appName: 'Projects',
    modelName: 'Project',
    idProperty: 'id',
    titleProperty: 'title',
    // ngettext('Project', 'Projects', n);
    recordName: 'Project',
    recordsName: 'Projects',
    containerProperty: 'container_id',
    // ngettext('Project list', 'Project lists', n);
    containerName: 'Project list',
    containersName: 'Project lists'
});

/**
 * @namespace Tine.Projects.Model
 * 
 * get default data for a new record
 *  
 * @return {Object} default data
 * @static
 * 
 * TODO generalize default container id handling
 */ 
Tine.Projects.Model.Project.getDefaultData = function() { 
    var app = Tine.Tinebase.appMgr.get('Projects');
    var defaultsContainer = Tine.Projects.registry.get('defaultContainer');
    
    return {
        container_id: app.getMainScreen().getWestPanel().getContainerTreePanel().getSelectedContainer('addGrant', defaultsContainer)
        // TODO add more defaults
    };
};

/**
 * get filtermodel of projects
 * 
 * @namespace Tine.ExampleApplication.Model
 * @static
 * @return {Object} filterModel definition
 */ 
Tine.Projects.Model.Project.getFilterModel = function() {
    var app = Tine.Tinebase.appMgr.get('Projects');
    
    return [
        {label: _('Quick search'),    field: 'query',       operators: ['contains']},
        {filtertype: 'tinebase.tag', app: app},
        {filtertype: 'tine.widget.container.filtermodel', app: app, recordClass: Tine.Projects.Model.Project},
        {label: app.i18n._('Last modified'),                                            field: 'last_modified_time', valueType: 'date'},
        {label: app.i18n._('Last modifier'),                                            field: 'last_modified_by',   valueType: 'user'},
        {label: app.i18n._('Creation Time'),                                            field: 'creation_time',      valueType: 'date'},
        {label: app.i18n._('Creator'),                                                  field: 'created_by',         valueType: 'user'}
    ];
};

/**
 * default Project backend
 */
Tine.Projects.recordBackend = new Tine.Tinebase.data.RecordProxy({
    appName: 'Projects',
    modelName: 'Project',
    recordClass: Tine.Projects.Model.Project
});
