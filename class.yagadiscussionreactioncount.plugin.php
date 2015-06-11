<?php

$PluginInfo['YagaDiscussionReactionCount'] = array(
    'Name' => 'Yaga Discussion Reaction Count',
    'Description' => 'Displays the total number of reactions of a discussion in the discussion meta. Run dba/counts after enabling if you already have reaction records.',
    'Version' => '0.1',
    'RequiredApplications' => array('Yaga' => '1.0'),
    'MobileFriendly' => true,
    'Author' => 'Bleistivt',
    'AuthorUrl' => 'http://bleistivt.net',
    'License' => 'GNU GPL2'
);

class YagaDiscussionReactionCountPlugin extends Gdn_Plugin {

    // If there are reactions, add the count to the DiscussionMeta everywhere.
    public function base_beforeDiscussionMeta_handler($sender, $args) {
        $countReactions = $args['Discussion']->CountReactions;

        if (C('Yaga.Reactions.Enabled') && $countReactions) {
            $number = bigPlural($countReactions, T('%s reaction'));
            echo wrap(
                sprintf(pluralTranslate($countReactions, T('%s reaction'), T('%s reactions')), $number),
                'span',
                array('class' => 'MItem MCount ReactionCount')
            );
        }
    }


    // Count when a reaction is saved.
    public function reactionModel_afterReactionSave_handler($sender, $args) {
        $discussionID = false;
        // Can a DiscussionID be found for this item?
        if ($args['ParentType'] == 'discussion') {
            $discussionID = $args['ParentID'];
        } elseif ($args['ParentType'] == 'comment') {
            $discussionID = val('DiscussionID', getRecord('comment', $args['ParentID']));
        }

        // Does this action change the reaction count for this item?
        if ($args['Exists'] === false) {
            $incDec = ' - 1';
        } elseif (!$args['CurrentReaction']) {
            $incDec = ' + 1';
        } else return;

        Gdn::sql()
            ->update('User')
            ->set('CountReactions', 'CountReactions'.$incDec, false)
            ->where('UserID', $args['ParentUserID'])
            ->put();

        if (!$discussionID) return;
        // Update the count in the discussion table.
        Gdn::sql()
            ->update('Discussion')
            ->set('CountReactions', 'CountReactions'.$incDec, false)
            ->where('DiscussionID', $discussionID)
            ->put();
    }


    // Register the dba/counts handlers.
    public function dbaController_countJobs_handler($sender) {
        $sender->Data['Jobs']['Recalculate Discussion.CountReactions'] = '/plugin/yagadrcounts.json';
        $sender->Data['Jobs']['Recalculate User.CountReactions'] = '/plugin/yagaurcounts.json';
    }


    // Recalculate reaction counts for all discussions (including comments).
    public function pluginController_yagaDRcounts_create($sender) {
        $sender->permission('Garden.Settings.Manage');

        $database = Gdn::database();
        $px = $database->DatabasePrefix;

        if (Gdn::structure()->hasEngine('memory')) {
            $database->query(
                "create temporary table {$px}CommentReactionCounts ENGINE=MEMORY as (
                    select c.DiscussionID, (
                    select count(r.ReactionID)
                    from {$px}Reaction r
                    where r.ParentType = 'comment' and c.CommentID = r.ParentID
                  ) as CountReactions from {$px}Comment c having CountReactions <> 0
                )"
            );
            $database->query(
                "update {$px}Discussion d set d.CountReactions = (
                    select count(r.ReactionID)
                    from {$px}Reaction r
                    where r.ParentType = 'discussion' and d.DiscussionID = r.ParentID
                )"
            );
            $database->query(
                "update {$px}Discussion d set d.CountReactions = d.CountReactions + ifnull((
                    select sum(c.CountReactions)
                    from {$px}CommentReactionCounts c
                    where c.DiscussionID = d.DiscussionID
                ), 0)"
            );
        } else {
            // Slow fallback in case we can't create tables in memory.
                $database->Query(
                "update {$px}Discussion p set p.CountReactions = (
                    select count(c.ReactionID)
                    from {$px}Reaction c
                    where (p.DiscussionID = c.ParentID and c.ParentType = 'discussion')
                ) + (
                    select count(c.ReactionID)
                    from {$px}Reaction c
                    left join {$px}Comment j on (c.ParentType = 'comment' and j.CommentID = c.ParentID)
                    where p.DiscussionID = j.DiscussionID
                )"
            );
        }

        $sender->setData('Result', array('Complete' => true));
        $sender->renderData();
    }


    // Recalculate user reaction counts.
    public function pluginController_yagaURcounts_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        Gdn::database()->query(DBAModel::getCountSql(
            'count',
            'User', 'Reaction',
            'CountReactions', 'ReactionID',
            'UserID', 'ParentAuthorID'
        ));
        $sender->setData('Result', array('Complete' => true));
        $sender->renderData();
    }


    // Create new columns to save the counts.
    public function structure() {
        $structure = Gdn::structure();

        $structure->table('Discussion')
            ->column('CountReactions', 'int(11)', 0)
            ->set();
        $structure->table('User')
            ->column('CountReactions', 'int(11)', 0)
            ->set();
    }


    public function setup() {
        $this->structure();
    }

}
