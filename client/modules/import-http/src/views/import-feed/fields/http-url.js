/*
 * This file is part of premium software, which is NOT free.
 * Copyright (c) AtroCore UG (haftungsbeschränkt).
 *
 * This Software is the property of AtroCore UG (haftungsbeschränkt) and is
 * protected by copyright law - it is NOT Freeware and can be used only in one
 * project under a proprietary license, which is delivered along with this program.
 * If not, see <https://atropim.com/eula> or <https://atrodam.com/eula>.
 *
 * This Software is distributed as is, with LIMITED WARRANTY AND LIABILITY.
 * Any unauthorised use of this Software without a valid license is
 * a violation of the License Agreement.
 *
 * According to the terms of the license you shall not resell, sublicense,
 * rent, lease, distribute or otherwise transfer rights or usage of this
 * Software or its derivatives. You may modify the code of this Software
 * for your own needs, if source code is provided.
 */

Espo.define('import-http:views/import-feed/fields/http-url', 'views/fields/varchar',
    Dep => Dep.extend({

        events: {
            'click .action[data-action="executeHttpRequest"]': function () {
                this.actionExecuteHttpRequest();
            }
        },

        setup() {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'change:httpUrl change:adapter', () => {
                this.ajaxGetRequest(`ImportHttp/action/getAllColumns?httpUrl=${this.model.get('httpUrl')}&adapter=${this.model.get('adapter')}`).success(allColumns => {
                    this.model.set('allColumns', allColumns);
                });
            });
        },

        afterRender() {
            Dep.prototype.afterRender.call(this);

            let button = '<button type="button" style="margin-top: 6px" class="btn btn-default action" data-action="executeHttpRequest">' + this.translate('execute', 'labels', 'ImportFeed') + '</button>'
            if (this.mode === 'detail') {
                button = '<br>' + button;
            }

            this.$el.append(button)
        },

        actionExecuteHttpRequest() {
            this.notify('Loading...');
            this.ajaxGetRequest(`ImportHttp/action/getAllColumns?httpUrl=${this.model.get('httpUrl')}&adapter=${this.model.get('adapter')}`).success(allColumns => {
                this.model.set('allColumns', allColumns);
                this.notify('Success', 'success');
                $('.action[data-action=refresh][data-panel=configuratorItems]').click();
            });
        },

    })
);
