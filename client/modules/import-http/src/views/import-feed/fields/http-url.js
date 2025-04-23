/**
 * AtroCore Software
 *
 * This source file is available under GNU General Public License version 3 (GPLv3).
 * Full copyright and license information is available in LICENSE.txt, located in the root directory.
 *
 * @copyright  Copyright (c) AtroCore GmbH (https://www.atrocore.com)
 * @license    GPLv3 (https://www.gnu.org/licenses/)
 */

Espo.define('import-http:views/import-feed/fields/http-url', 'views/fields/script',
    Dep => {

        return Dep.extend({

            initInlineActions() {
                this.listenTo(this, 'after:render', this.initMagicIcon, this);

                Dep.prototype.initInlineActions.call(this);
            },

            initMagicIcon() {
                const $cell = this.getCellElement();

                $cell.find('.ph-magic-wand').parent().remove();

                const $link = $('<a href="javascript:" class="pull-right hidden generate-url" title="' + this.translate('generateURL', 'labels', 'ImportFeed') + '"><i class="ph ph-magic-wand"></i></a>');

                $cell.prepend($link);

                $link.on('click', () => {
                    this.confirm({
                        message: this.translate('confirmUrlGeneration', 'messages', 'ImportFeed'),
                        confirmText: this.translate('Apply')
                    }, () => {
                        this.ajaxPostRequest('ImportHttp/action/generateURL', {importFeedId: this.model.get('id')}).then(res => {
                            if (res['url'] === this.model.get(this.name)) {
                                Espo.Ui.notify(this.translate('notModified', 'messages'), 'warning');
                            } else {
                                this.model.set(this.name, res['url']);
                                this.model.save().then(() => {
                                    this.notify('Saved', 'success');
                                });
                            }
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