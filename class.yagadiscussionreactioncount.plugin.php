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

class YagaDiscussionReactionCount extends Gdn_Plugin {

    private $currentReaction;

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

    // Hacky way to get the previous reaction to determine later if the count has to change.
    public function reactController_initialize_handler($sender) {
        $args = explode('/', trim($sender->SelfUrl, '/'));
        if (count($args) > 3) {
            $args = array_slice($args, -3);
            $this->currentReaction = $sender->ReactionModel->getByUser($args[1], $args[0], Gdn::session()->UserID);
        }
    }

    // Count when a reaction is saved.
    public function reactionModel_afterReactionSave_handler($sender, $args) {
        $discussionID = false;
        // Can a DiscussionID be found for this item?
        if ($args['ParentType'] == 'discussion') {
            $discussionID = $args['ParentID'];
        } elseif ($args['ParentType'] == 'comment') {
            $discussionID = Gdn::sql()
                ->getWhere('Comment', array('CommentID' => $args['ParentID']))
                ->firstRow()
                ->DiscussionID;
        } else return;

        // Does this action change the reaction count for this item?
        if ($args['Exists'] === false) {
            $incDec = ' - 1';
        } elseif (!$this->currentReaction) {
            $incDec = ' + 1';
        } else return;

        // Update the count in the discussion table.
        Gdn::sql()
            ->update('Discussion')
            ->set('CountReactions', 'CountReactions'.$incDec, false)
            ->where('DiscussionID', $discussionID)
            ->put();
    }

    // Register a dba/counts handler.
    public function dbaController_countJobs_handler($sender) {
        $sender->Data['Jobs']['Recalculate Discussion.CountReactions'] = '/plugin/yagadrcounts.json';
    }

    // Recalculate reaction counts for all discussions.
    public function pluginController_yagaDRCounts_create($sender) {
        $sender->permission('Garden.Settings.Manage');

        $database = Gdn::Database();
        $px = $database->DatabasePrefix;

        $database->Query(
            "update {$px}Discussion p set p.CountReactions =
              (select count(c.ReactionID)
                from {$px}Reaction c
                where (p.DiscussionID = c.ParentID and c.ParentType = 'discussion')
              ) +
              (select count(c.ReactionID)
                from {$px}Reaction c
                left join {$px}Comment j on (c.ParentType = 'comment' and j.CommentID = c.ParentID)
                where p.DiscussionID = j.DiscussionID
              )"
        );

        $sender->setData('Result', array('Complete' => true));
        $sender->renderData();
    }

    // Create a new column to save the counts
    public function structure() {
        GDN::structure()->table('Discussion')
            ->column('CountReactions', 'int(11)', 0)
            ->set();
    }

    public function setup() {
        $this->structure();
    }

}
