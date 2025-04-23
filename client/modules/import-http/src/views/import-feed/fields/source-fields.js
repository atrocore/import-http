/**
 * AtroCore Software
 *
 * This source file is available under GNU General Public License version 3 (GPLv3).
 * Full copyright and license information is available in LICENSE.txt, located in the root directory.
 *
 * @copyright  Copyright (c) AtroCore GmbH (https://www.atrocore.com)
 * @license    GPLv3 (https://www.gnu.org/licenses/)
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
                const actions = this.getInlineActionsContainer();

                $cell.find('.ph-magic-wand').parent().remove();

                if (!this.model.get('httpUrl')) {
                    return;
                }

                const $link = $('<a href="javascript:" class="pull-right hidden generate-source-fields" title="' + this.translate('generateSourceFields', 'labels', 'ImportFeed') + '"><i class="ph ph-magic-wand"></i></a>');

                if (actions && actions.size() > 0) {
                    actions.prepend($link);
                } else {
                    $cell.prepend($link);
                }

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