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

Espo.define('import-http:views/import-feed/fields/source-fields', 'import:views/import-feed/fields/source-fields',
    Dep => {

        return Dep.extend({

            initInlineActions() {
                this.listenTo(this, 'after:render', this.initMagicIcon, this);

                Dep.prototype.initInlineActions.call(this);
            },

            initMagicIcon() {
                const $cell = this.getCellElement();

                $cell.find('.fa-magic').parent().remove();

                if (!this.model.get('httpUrl')) {
                    return;
                }

                const $link = $('<a href="javascript:" class="pull-right hidden generate-source-fields" title="' + this.translate('generateSourceFields', 'labels', 'ImportFeed') + '"><span class="fas fa-magic fa-sm"></span></a>');

                $cell.prepend($link);

                $link.on('click', () => {
                    this.confirm({
                        message: this.translate('confirmSourceFieldsGeneration', 'messages', 'ImportFeed'),
                        confirmText: this.translate('Apply')
                    }, () => {
                        this.ajaxPostRequest('ImportHttp/action/generateSourceFields', {importFeedId: this.model.get('id')}).then(res => {
                            this.model.set(res);
                            this.model.save().then(() => {
                                this.notify('Saved', 'success');
                            });
                        });
                    });

                });

                $cell.on('mouseenter', function (e) {
                    e.stopPropagation();
                    if (this.disabled || this.readOnly) {
                        return;
                    }
                    if (this.mode === 'detail') {
                        $link.removeClass('hidden');
                    }
                }.bind(this)).on('mouseleave', function (e) {
                    e.stopPropagation();
                    if (this.mode === 'detail') {
                        $link.addClass('hidden');
                    }
                }.bind(this));
            },

        });

    });