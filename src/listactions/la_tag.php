<?php
// listactions/la_tag.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Tag_ListAction extends ListAction {
    static function render(PaperList $pl, Qrequest $qreq) {
        // tagtype cell
        $tagopt = array("a" => "Add", "d" => "Remove", "s" => "Define", "xxxa" => null, "ao" => "Add to order", "aos" => "Add to gapless order", "so" => "Define order", "sos" => "Define gapless order", "sor" => "Define random order");
        $tagextra = ["class" => "js-submit-action-info-tag"];
        if ($pl->user->privChair) {
            $tagopt["xxxb"] = null;
            $tagopt["cr"] = "Calculate rank";
        }

        // tag name cell
        $t = "";
        if ($pl->user->privChair) {
            $t .= '<span class="fx99"><a class="ui q js-foldup" href="">'
                . expander(null, 0) . "</a></span>";
        }
        $t .= 'tag<span class="fn99">(s)</span> &nbsp;'
            . Ht::entry("tag", $qreq->tag,
                        ["size" => 15, "class" => "want-focus js-autosubmit js-submit-action-info-tag need-suggest tags", "data-autosubmit-type" => "tag"])
            . ' &nbsp;' . Ht::submit("fn", "Go", ["value" => "tag", "class" => "uic js-submit-mark"]);
        if ($pl->user->privChair) {
            $t .= '<div class="fx"><div style="margin:2px 0">'
                . Ht::checkbox("tagcr_gapless", 1, !!$qreq->tagcr_gapless, array("style" => "margin-left:0"))
                . "&nbsp;" . Ht::label("Gapless order") . "</div>"
                . '<div style="margin:2px 0">Using: &nbsp;'
                . Ht::select("tagcr_method", PaperRank::methods(), $qreq->tagcr_method)
                . "</div>"
                . '<div style="margin:2px 0">Source tag: &nbsp;~'
                . Ht::entry("tagcr_source", $qreq->tagcr_source, array("size" => 15))
                . "</div></div>";
        }

        return [Ht::select("tagfn", $tagopt, $qreq->tagfn, $tagextra) . " &nbsp;",
            ["linelink-class" => "has-fold foldc fold99c ui-unfold js-tag-list-action", "content" => $t]];
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_change_some_tag();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $papers = $ssel->selection();

        $act = $qreq->tagfn;
        $tagreq = trim(str_replace(",", " ", (string) $qreq->tag));
        $tags = preg_split('/\s+/', $tagreq);

        if ($act == "da") {
            $otags = $tags;
            foreach ($otags as $t) {
                $tags[] = "all~" . preg_replace('/\A.*~([^~]+)\z/', '$1', $t);
            }
            $act = "d";
        } else if ($act == "sor") {
            shuffle($papers);
        }

        $x = array("action,paper,tag\n");
        if ($act === "s" || $act === "so" || $act === "sos" || $act === "sor") {
            foreach ($tags as $t) {
                $x[] = "cleartag,all," . Tagger::base($t) . "\n";
            }
        }
        if ($act === "s" || $act === "a") {
            $action = "tag";
        } else if ($act === "d") {
            $action = "cleartag";
        } else if ($act === "so" || $act === "sor" || $act === "ao") {
            $action = "nexttag";
        } else if ($act === "sos" || $act === "aos") {
            $action = "seqnexttag";
        } else {
            $action = null;
        }

        $assignset = new AssignmentSet($user, Contact::OVERRIDE_CONFLICT);
        if (!empty($papers) && $action) {
            foreach ($papers as $p) {
                foreach ($tags as $t) {
                    $x[] = "$action,$p,$t\n";
                }
            }
            $assignset->parse(join("", $x));
        } else if (!empty($papers) && $act == "cr" && $user->privChair) {
            $source_tag = trim((string) $qreq->tagcr_source);
            if ($source_tag === "") {
                $source_tag = (substr($tagreq, 0, 2) === "~~" ? substr($tagreq, 2) : $tagreq);
            }
            $tagger = new Tagger($user);
            if ($tagger->check($tagreq, Tagger::NOPRIVATE | Tagger::NOVALUE)
                && $tagger->check($source_tag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
                $r = new PaperRank($user->conf, $source_tag, $tagreq, $papers,
                                   $qreq->tagcr_gapless, "Search", "search");
                $r->run($qreq->tagcr_method);
                $assignset->set_overrides(Contact::OVERRIDE_CONFLICT | Contact::OVERRIDE_TAG_CHECKS);
                $assignset->parse($r->unparse_assignment());
                if ($qreq->q === "") {
                    $qreq->q = "order:$tagreq";
                }
            } else {
                $assignset->error($tagger->error_html);
            }
        }
        if (($errors = $assignset->messages_div_html())) {
            if ($assignset->is_empty()) {
                Conf::msg_error($errors);
            } else {
                Conf::msg_warning("Some tag assignments were ignored:\n$errors");
                $assignset->clear_errors();
            }
        }
        $success = $assignset->execute();

        if ($qreq->ajax) {
            json_exit(["ok" => $success]);
        } else if ($success) {
            if (!$errors) {
                $user->conf->confirmMsg("Tags saved.");
            }
            $args = ["atab" => "tag"] + $qreq->subset_as_array(["tag", "tagfn", "tagcr_method", "tagcr_source", "tagcr_gapless"]);
            $user->conf->redirect_self($qreq, $args);
        }
    }
}
