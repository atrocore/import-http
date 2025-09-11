/**
 * AtroCore Software
 *
 * This source file is available under GNU General Public License version 3 (GPLv3).
 * Full copyright and license information is available in LICENSE.txt, located in the root directory.
 *
 * @copyright  Copyright (c) AtroCore GmbH (https://www.atrocore.com)
 * @license    GPLv3 (https://www.gnu.org/licenses/)
 */

Espo.define('import-http:views/import-feed/fields/http-connection', 'views/fields/link',
    Dep => {
        return Dep.extend({

            createDisabled: true,

            selectBoolFilterList: ['notEntity', 'httpConnection'],

            boolFilterData: {
                notEntity() {
                    return this.model.get('httpConnectionId');
                }
            },

            setup: function () {
                this.name = 'httpConnection'
                this.foreignScope = 'Connection'

                Dep.prototype.setup.call(this);
            },

            getConditions(type) {
                return this.getMetadata().get(`entityDefs.${this.model.name}.fields.httpConnectionId.conditionalProperties.${type}.conditionGroup`);
            },

        });

    });