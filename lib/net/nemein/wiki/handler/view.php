<?php
/**
 * @package net.nemein.wiki
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use midcom\datamanager\datamanager;

/**
 * Wikipage view handler
 *
 * @package net.nemein.wiki
 */
class net_nemein_wiki_handler_view extends midcom_baseclasses_components_handler
{
    use net_nemein_wiki_handler;

    /**
     * The wikipage we're viewing
     *
     * @var net_nemein_wiki_wikipage
     */
    private $_page;

    /**
     * The Datamanager for article to display
     *
     * @var datamanager
     */
    private $_datamanager;

    public function _on_initialize()
    {
        $this->_request_data['page'] =& $this->_page;
    }

    /**
     * Internal helper, loads the datamanager for the current wikipage. Any error triggers a 500.
     */
    private function _load_datamanager()
    {
        $this->_datamanager = datamanager::from_schemadb($this->_config->get('schemadb'))
            ->set_storage($this->_page);
    }

    private function _populate_toolbar()
    {
        $workflow = $this->get_workflow('datamanager');
        $buttons = [
            [
                MIDCOM_TOOLBAR_URL => "{$this->_page->name}/",
                MIDCOM_TOOLBAR_LABEL => $this->_l10n_midcom->get('view'),
                MIDCOM_TOOLBAR_GLYPHICON => 'search',
                MIDCOM_TOOLBAR_ACCESSKEY => 'v',
            ],
            $workflow->get_button("edit/{$this->_page->name}/", [
                MIDCOM_TOOLBAR_ACCESSKEY => 'e',
                MIDCOM_TOOLBAR_ENABLED => $this->_page->can_do('midgard:update'),
            ])
        ];
        if ($this->_page->can_do('midgard:delete')) {
            $workflow = $this->get_workflow('delete', ['object' => $this->_page]);
            $buttons[] = $workflow->get_button("delete/{$this->_page->name}/");
        }

        $buttons[] = [
            MIDCOM_TOOLBAR_URL => "whatlinks/{$this->_page->name}/",
            MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('what links'),
            MIDCOM_TOOLBAR_GLYPHICON => 'link',
        ];

        if (midcom::get()->auth->user) {
            $user = midcom::get()->auth->user->get_storage();
            if ($this->_page->get_parameter('net.nemein.wiki:watch', $user->guid)) {
                $action = 'unsubscribe';
            } else {
                $action = 'subscribe';
            }
            $buttons[] = [
                MIDCOM_TOOLBAR_URL => "subscribe/{$this->_page->name}/",
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get($action),
                MIDCOM_TOOLBAR_GLYPHICON => 'envelope-o',
                MIDCOM_TOOLBAR_POST => true,
                MIDCOM_TOOLBAR_POST_HIDDENARGS => [$action => 1],
            ];
        }

        if ($this->_page->can_do('midgard:update')) {
            midcom::get()->head->add_link_head([
                'rel' => 'alternate',
                'type' => 'application/x-wiki',
                'title' => $this->_l10n_midcom->get('edit'),
                'href' => $this->router->generate('edit', ['wikipage' => $this->_page->name]),
            ]);
        }
        $this->_view_toolbar->add_items($buttons);
        org_openpsa_relatedto_plugin::add_button($this->_view_toolbar, $this->_page->guid);

        $this->bind_view_to_object($this->_page, $this->_datamanager->get_schema()->get_name());
    }

    private function _load_page($wikiword, $autocreate = true)
    {
        $qb = net_nemein_wiki_wikipage::new_query_builder();
        $qb->add_constraint('topic', '=', $this->_topic->id);
        $qb->add_constraint('name', '=', $wikiword);
        midcom::get()->auth->request_sudo($this->_component);
        $result = $qb->execute();
        midcom::get()->auth->drop_sudo();
        if (count($result) > 0) {
            $this->_page = $result[0];
            $this->_page->require_do('midgard:read');
            return;
        }

        if ($wikiword == 'index') {
            // Autoinitialize
            $this->_topic->require_do('midgard:create');
            $this->_page = $this->initialize_index_article($this->_topic);
            return;
        }

        // We need to get the node from NAP for safe redirect
        $nap = new midcom_helper_nav();
        $node = $nap->get_node($this->_topic->id);
        $generator = midcom::get()->serviceloader->load(midcom_core_service_urlgenerator::class);
        $urlized_wikiword = $generator->from_string($wikiword);
        if ($urlized_wikiword != $wikiword) {
            // Lets see if the page for the wikiword exists
            $qb = net_nemein_wiki_wikipage::new_query_builder();
            $qb->add_constraint('topic', '=', $this->_topic->id);
            $qb->add_constraint('title', '=', $wikiword);
            $result = $qb->execute();
            if (count($result) > 0) {
                // This wiki page actually exists, so go there as "Permanent Redirect"
                midcom::get()->relocate("{$node[MIDCOM_NAV_ABSOLUTEURL]}{$result[0]->name}/", 301);
            }
        }
        if ($autocreate) {
            midcom::get()->relocate("{$node[MIDCOM_NAV_ABSOLUTEURL]}notfound/" . rawurlencode($wikiword) . '/');
            // This will exit
        }
        throw new midcom_error_notfound('The page ' . $wikiword . ' could not be found.');
    }

    /**
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_view($handler_id, $args, &$data)
    {
        if (count($args) == 0) {
            $this->_load_page('index');
        } else {
            $this->_load_page($args[0]);
        }

        $this->_load_datamanager();

        if ($this->_datamanager->get_schema()->get_name() == 'redirect') {
            $qb = net_nemein_wiki_wikipage::new_query_builder();
            $qb->add_constraint('topic.component', '=', 'net.nemein.wiki');
            $qb->add_constraint('name', '=', $this->_page->url);
            $result = $qb->execute();
            if (count($result) == 0) {
                // No matching redirection page found, relocate to editing
                // TODO: Add UI message
                return new midcom_response_relocate("edit/{$this->_page->name}/");
            }

            if ($result[0]->topic == $this->_topic->id) {
                return new midcom_response_relocate("{$result[0]->name}/");
            }
            return new midcom_response_relocate(midcom::get()->permalinks->create_permalink($result[0]->guid));
        }

        $this->_populate_toolbar();
        $this->_view_toolbar->hide_item("{$this->_page->name}/");

        if ($this->_page->name != 'index') {
            $this->add_breadcrumb("{$this->_page->name}/", $this->_page->title);
        }

        midcom::get()->head->set_pagetitle($this->_page->title);
        midcom::get()->metadata->set_request_metadata($this->_page->metadata->revised, $this->_page->guid);

        $data['wikipage_view'] = $this->_datamanager->get_content_html();
        $data['wikipage'] = $this->_page;
        $data['display_related_to'] = $this->_config->get('display_related_to');

        // Replace wikiwords
        // TODO: We should somehow make DM2 do this so it would also work in AJAX previews
        $parser = new net_nemein_wiki_parser($this->_page);
        $data['wikipage_view']['content'] = $parser->get_markdown($data['wikipage_view']['content']);
        if ($this->_config->get('autogenerate_toc')) {
            $data['wikipage_view']['content'] = $this->_autogenerate_toc($data['wikipage_view']['content']);
        }

        return $this->show('view-wikipage');
    }

    /**
     * Parse HTML content and look for header tags, making index of them.
     *
     * It looks for all H<num> tags and converts them to named
     * anchors, and prepends a list of links to them to the start of HTML.
     */
    private function _autogenerate_toc($content)
    {
        if (!preg_match_all("/(<(h([1-9][0-9]*))[^>]*?>)(.*?)(<\/\\2>)/i", $content, $headings)) {
            return $content;
        }

        $toc = '';

        $current_tag_level = false;
        $current_list_level = 1;
        $toc .= "\n<ol class=\"midcom_helper_toc_formatter level_{$current_list_level}\">\n";
        foreach ($headings[4] as $key => $heading) {
            $anchor = 'heading-' . md5($heading);
            $tag_level =& $headings[3][$key];
            $heading_code =& $headings[0][$key];
            $heading_tag =& $headings[2][$key];
            $heading_new_code = "<a id='{$anchor}'></a>{$heading_code}";
            $content = str_replace($heading_code, $heading_new_code, $content);
            if ($current_tag_level === false) {
                $current_tag_level = $tag_level;
            } elseif ($current_tag_level == $tag_level) {
                $toc .= "</li>\n";
            } elseif ($tag_level > $current_tag_level) {
                for ($i = $current_tag_level; $i < $tag_level; $i++) {
                    $current_tag_level = $tag_level;
                    $current_list_level++;
                    $toc .= "<ol class=\"level_{$current_list_level}\">\n";
                    if ($tag_level > $i + 1) {
                        $toc .= "<li>\n";
                    }
                }
            } else {
                for ($i = $current_tag_level; $i > $tag_level; $i--) {
                    $toc .= "</li>\n";
                    $current_tag_level = $tag_level;
                    if ($current_list_level > 1) {
                        $current_list_level--;
                        $toc .= "</ol>\n";
                    }
                }
            }
            $toc .= "<li class='{$heading_tag}'><a href='#{$anchor}'>" . strip_tags($heading) .  "</a>";
        }
        for ($i = $current_list_level; $i > 0; $i--) {
            $toc .= "</li>\n</ol>\n";
        }

        return $toc . $content;
    }

    /**
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_raw($handler_id, $args, &$data)
    {
        $this->_load_page($args[0], false);
        midcom::get()->skip_page_style = true;
        $this->_load_datamanager();

        $data['wikipage_view'] = $this->_datamanager->get_content_html();
        $data['autogenerate_toc'] = $this->_config->get('autogenerate_toc');
        $data['display_related_to'] = $this->_config->get('display_related_to');

        // Replace wikiwords
        $parser = new net_nemein_wiki_parser($this->_page);
        $data['wikipage_view']['content'] = $parser->get_markdown($data['wikipage_view']['content']);

        return $this->show('view-wikipage-raw');
    }

    /**
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_subscribe($handler_id, array $args, array &$data)
    {
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            throw new midcom_error_forbidden('Only POST requests are allowed here.');
        }

        midcom::get()->auth->require_valid_user();

        $this->_load_page($args[0], false);

        midcom::get()->auth->request_sudo('net.nemein.wiki');

        $user = midcom::get()->auth->user->get_storage();

        if (   array_key_exists('target', $_POST)
            && $_POST['target'] == 'folder') {
            // We're subscribing to the whole wiki
            $object = $this->_topic;
            $target = sprintf($this->_l10n->get('whole wiki %s'), $this->_topic->extra);
        } else {
            $object = $this->_page;
            $target = sprintf($this->_l10n->get('page %s'), $this->_page->title);
        }

        if (array_key_exists('subscribe', $_POST)) {
            // Subscribe to page
            $object->set_parameter('net.nemein.wiki:watch', $user->guid, time());
            midcom::get()->uimessages->add($this->_l10n->get($this->_component), sprintf($this->_l10n->get('subscribed to changes in %s'), $target));
        } else {
            // Remove subscription
            $object->delete_parameter('net.nemein.wiki:watch', $user->guid);
            midcom::get()->uimessages->add($this->_l10n->get($this->_component), sprintf($this->_l10n->get('unsubscribed from changes in %s'), $target));
        }

        midcom::get()->auth->drop_sudo();

        // Redirect to editing
        if ($this->_page->name == 'index') {
            return new midcom_response_relocate("");
        }
        return new midcom_response_relocate("{$this->_page->name}/");
    }

    /**
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_whatlinks($handler_id, $args, &$data)
    {
        $this->_load_page($args[0], false);

        $this->_load_datamanager();

        $this->_populate_toolbar();
        $this->_view_toolbar->hide_item("whatlinks/{$this->_page->name}/");
        $this->add_breadcrumb("{$this->_page->name}/", $this->_page->title);

        $qb = net_nemein_wiki_link_dba::new_query_builder();
        $qb->add_constraint('topage', '=', $this->_page->title);
        $data['wikilinks'] = $qb->execute();
        $data['wikipage_view'] = $this->_datamanager->get_content_html();

        // Replace wikiwords
        $parser = new net_nemein_wiki_parser($this->_page);
        $data['wikipage_view']['content'] = $parser->get_markdown($data['wikipage_view']['content']);

        return $this->show('view-wikipage-whatlinks');
    }

    /**
     * Callback for sorting wikipages by title
     *
     * @param net_nemein_wiki_wikipage $a
     * @param net_nemein_wiki_wikipage $b
     */
    public static function sort_by_title(net_nemein_wiki_wikipage $a, net_nemein_wiki_wikipage $b)
    {
        return strnatcmp($a->title, $b->title);
    }
}
