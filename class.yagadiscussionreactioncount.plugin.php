<?php if (!defined('APPLICATION')) exit();

$PluginInfo['YagaDiscussionReactionCount'] = array(
    'Name' => 'Yaga Discussion Reaction Count',
    'Description' => 'Displays the total number of reactions of a discussion in the discussion meta. Run dba/counts after enabling if you already have reaction records.',
    'Version' => '0.1',
    'RequiredApplications' => array('Yaga' => '1.0'),
    'MobileFriendly' => true,
    'Author' => 'Bleistivt',
    'AuthorUrl' => 'http://bleistivt.net'
);

class YagaDiscussionReactionCount extends Gdn_Plugin {

    private $CurrentReaction;

    // If there are reactions, add the count to the DiscussionMeta everywhere.
    public function Base_BeforeDiscussionMeta_Handler($Sender, $Args) {
        $CountReactions = $Args['Discussion']->CountReactions;

        if (C('Yaga.Reactions.Enabled') && $CountReactions) {
            $Number = BigPlural($CountReactions, T('%s reaction'));
            echo Wrap(
                sprintf(PluralTranslate($CountReactions, T('%s reaction'), T('%s reactions')), $Number),
                'span',
                array('class' => 'MItem MCount ReactionCount')
            );
        }
    }

    // Hacky way to get the previous reaction to determine later if the count has to change.
    public function ReactController_Initialize_Handler($Sender) {
        $Args = explode('/', trim($Sender->SelfUrl, '/'));
        if (count($Args) > 3) {
            $Args = array_slice($Args, -3);
            $this->CurrentReaction = $Sender->ReactionModel->GetByUser($Args[1], $Args[0], Gdn::Session()->UserID);
        }
    }

    // Count when a reaction is saved.
    public function ReactionModel_AfterReactionSave_Handler($Sender, $Args) {
        $DiscussionID = false;
        // Can a DiscussionID be found for this item?
        if ($Args['ParentType'] == 'discussion') {
            $DiscussionID = $Args['ParentID'];
        } elseif ($Args['ParentType'] == 'comment') {
            $DiscussionID = Gdn::SQL()
                ->GetWhere('Comment', array('CommentID' => $Args['ParentID']))
                ->FirstRow()
                ->DiscussionID;
        } else return;

        // Does this action change the reaction count for this item?
        if ($Args['Exists'] === false) {
            $IncDec = ' - 1';
        } elseif (!$this->CurrentReaction) {
            $IncDec = ' + 1';
        } else return;

        // Update the count in the discussion table.
        Gdn::SQL()
            ->Update('Discussion')
            ->Set('CountReactions', 'CountReactions'.$IncDec, false)
            ->Where('DiscussionID', $DiscussionID)
            ->Put();
    }

    // Register a dba/counts handler.
    public function DbaController_CountJobs_Handler($Sender) {
        $Sender->Data['Jobs']['Recalculate Discussion.CountReactions'] = '/plugin/YagaDRCounts.json';
    }

    // Recalculate reaction counts for all discussions.
    public function PluginController_YagaDRCounts_Create($Sender) {
        $Sender->Permission('Garden.Settings.Manage');

        $Database = Gdn::Database();
        $Prefix = $Database->DatabasePrefix;

        $Database->Query(
            "update {$Prefix}Discussion p set p.CountReactions =
              (select count(c.ReactionID)
                from {$Prefix}Reaction c
                where (p.DiscussionID = c.ParentID and c.ParentType = 'discussion')
              ) +
              (select count(c.ReactionID)
                from {$Prefix}Reaction c
                left join {$Prefix}Comment j on (c.ParentType = 'comment' and j.CommentID = c.ParentID)
                where (p.DiscussionID = j.DiscussionID and c.ParentType = 'comment')
              )"
        );

        $Sender->SetData('Result', array('Complete' => true));
        $Sender->RenderData();
    }

    // Create a new column to save the counts
    public function Structure() {
        GDN::Structure()->Table('Discussion')
            ->Column('CountReactions', 'int(11)', 0)
            ->Set();
    }

    public function Setup() {
        $this->Structure();
    }

}
