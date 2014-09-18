<?php
namespace Netrunnerdb\BuilderBundle\Controller;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Netrunnerdb\BuilderBundle\Entity\Deck;
use Netrunnerdb\BuilderBundle\Entity\Deckslot;
use Netrunnerdb\CardsBundle\Entity\Card;
use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Request;

class BuilderController extends Controller
{

    public function buildformAction ($side_text, Request $request)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->container->getParameter('long_cache'));
        
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        $side = $em->getRepository('NetrunnerdbCardsBundle:Side')->findOneBy(array(
                "name" => $side_text
        ));
        $type = $em->getRepository('NetrunnerdbCardsBundle:Type')->findOneBy(array(
                "name" => "Identity"
        ));
        
        $identities = $em->getRepository('NetrunnerdbCardsBundle:Card')->findBy(array(
                "side" => $side,
                "type" => $type
        ), array(
                "faction" => "ASC",
                "title" => "ASC"
        ));
        
        return $this->render('NetrunnerdbBuilderBundle:Builder:initbuild.html.twig',
                array(
                        'pagetitle' => "New deck",
                        'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
                        "identities" => array_filter($identities,
                                function  ($iden)
                                {
                                    return $iden->getPack()
                                        ->getCode() != "alt";
                                })
                ), $response);
    
    }

    public function initbuildAction ($card_code)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->container->getParameter('long_cache'));
        
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        /* @var $card Card */
        $card = $em->getRepository('NetrunnerdbCardsBundle:Card')->findOneBy(array(
                "code" => $card_code
        ));
        if (! $card)
            return new Response('card not found.');
        
        $arr = array(
                $card_code => 1
        );
        return $this->render('NetrunnerdbBuilderBundle:Builder:deck.html.twig',
                array(
                        'pagetitle' => "Deckbuilder",
                        'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
                        'deck' => array(
                                'side_name' => mb_strtolower($card->getSide()
                                    ->getName()),
                                "slots" => $arr,
                                "name" => "New " . $card->getSide()
                                    ->getName() . " Deck",
                                "description" => "",
                                "tags" => $card->getFaction()->getCode(),
                                "id" => ""
                        ),
                        "published_decklists" => array()
                ), $response);
    
    }

    public function importAction ()
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->container->getParameter('long_cache'));
        
        return $this->render('NetrunnerdbBuilderBundle:Builder:directimport.html.twig',
                array(
                        'pagetitle' => "Import a deck",
                        'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig')
                ), $response);
    
    }

    public function fileimportAction (Request $request)
    {

        $filetype = filter_var($request->get('type'), FILTER_SANITIZE_STRING);
        $uploadedFile = $request->files->get('upfile');
        if (! isset($uploadedFile))
            return new Response('No file');
        $origname = $uploadedFile->getClientOriginalName();
        $origext = $uploadedFile->getClientOriginalExtension();
        $filename = $uploadedFile->getPathname();
        
        if (function_exists("finfo_open")) {
            // return mime type ala mimetype extension
            $finfo = finfo_open(FILEINFO_MIME);
            
            // check to see if the mime-type starts with 'text'
            $is_text = substr(finfo_file($finfo, $filename), 0, 4) == 'text' || substr(finfo_file($finfo, $filename), 0, 15) == "application/xml";
            if (! $is_text)
                return new Response('Bad file');
        }
        
        if ($filetype == "octgn" || ($filetype == "auto" && $origext == "o8d")) {
            $parse = $this->parseOctgnImport(file_get_contents($filename));
        } else {
            $parse = $this->parseTextImport(file_get_contents($filename));
        }
        return $this->forward('NetrunnerdbBuilderBundle:Builder:save',
                array(
                        'name' => $origname,
                        'content' => json_encode($parse['content']),
                        'description' => $parse['description']
                ));
    
    }

    public function parseTextImport ($text)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        $content = array();
        $lines = explode("\n", $text);
        $identity = null;
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d)x?([\pLl\pLu\pN\-\.\'\!\: ]+)/u', $line, $matches)) {
                $quantity = intval($matches[1]);
                $name = trim($matches[2]);
            } else
                if (preg_match('/^([^\(]+).*x(\d)/', $line, $matches)) {
                    $quantity = intval($matches[2]);
                    $name = trim($matches[1]);
                } else
                    if (empty($identity) && preg_match('/([^\(]+):([^\(]+)/', $line, $matches)) {
                        $quantity = 1;
                        $name = trim($matches[1] . ":" . $matches[2]);
                        $identity = $name;
                    } else {
                        continue;
                    }
            $card = $em->getRepository('NetrunnerdbCardsBundle:Card')->findOneBy(array(
                    'title' => $name
            ));
            if ($card) {
                $content[$card->getCode()] = $quantity;
            }
        }
        return array(
                "content" => $content,
                "description" => ""
        );
    
    }

    public function parseOctgnImport ($octgn)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        $content = array();
        
        $crawler = new Crawler();
        $crawler->addXmlContent($octgn);
        $cardcrawler = $crawler->filter('deck > section > card');
        
        $content = array();
        foreach ($cardcrawler as $domElement) {
            $quantity = intval($domElement->getAttribute('qty'));
            if (preg_match('/bc0f047c-01b1-427f-a439-d451eda(\d{5})/', $domElement->getAttribute('id'), $matches)) {
                $card_code = $matches[1];
            } else {
                continue;
            }
            $card = $em->getRepository('NetrunnerdbCardsBundle:Card')->findOneBy(array(
                    'code' => $card_code
            ));
            if ($card) {
                $content[$card->getCode()] = $quantity;
            }
        }
        
        $desccrawler = $crawler->filter('deck > notes');
        $description = array();
        foreach ($desccrawler as $domElement) {
            $description[] = $domElement->nodeValue;
        }
        return array(
                "content" => $content,
                "description" => implode("\n", $description)
        );
    
    }

    public function meteorimportAction (Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        // first build an array to match meteor card names with our card codes
        $glossary = array();
        $cards = $em->getRepository('NetrunnerdbCardsBundle:Card')->findAll();
        /* @var $card Card */
        foreach ($cards as $card) {
            $title = $card->getTitle();
            $replacements = array(
                    'Alix T4LB07' => 'Alix T4LBO7',
                    'Planned Assault' => 'Planned Attack',
                    'Security Testing' => 'Security Check',
                    'Mental Health Clinic' => 'Psychiatric Clinic',
                    'Shi.Kyū' => 'Shi Kyu',
                    'NeoTokyo Grid' => 'NeoTokyo City Grid',
                    'Push Your Luck' => 'Double or Nothing'
            );
            if (isset($replacements[$title])) {
                $title = $replacements[$title];
            }
            // rule to cut the subtitle of an identity
            if ($card->getPack()
                ->getCycle()
                ->getNumber() < 2 || ($card->getPack()
                ->getCycle()
                ->getNumber() == 2 && $card->getSide()->getName() == "Runner")) {
                $title = preg_replace('~:.*~', '', $title);
            }
            
            $pack = $card->getPack()->getName();
            if ($pack == "Core Set") {
                $pack = "Core";
            }
            
            $str = $title . " " . $pack;
            
            $str = str_replace('\'', '', $str);
            $str = strtr(utf8_decode($str), utf8_decode('ŠŒŽšœžŸ¥µÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýÿō'),
                    'SOZsozYYuAAAAAAACEEEEIIIIDNOOOOOOUUUUYsaaaaaaaceeeeiiiionoooooouuuuyyo');
            $str = strtolower($str);
            $str = preg_replace('~\W+~', '-', $str);
            $glossary[$str] = $card->getCode();
        }
        
        $url = $request->request->get('urlmeteor');
        if (! preg_match('~http://netrunner.meteor.com/users/([^/]+)~', $url, $matches)) {
            $this->get('session')
                ->getFlashBag()
                ->set('error', "Wrong URL. Please go to \"Your decks\" on Meteor Decks and copy the content of the address bar into the required field.");
            return $this->redirect($this->generateUrl('decks_list'));
        }
        $meteor_id = $matches[1];
        $meteor_json = file_get_contents("http://netrunner.meteor.com/api/decks/$meteor_id");
        $meteor_data = json_decode($meteor_json, true);
        
        // check to see if the user has enough available deck slots
        $user = $this->getUser();
        $slots_left = $user->getMaxNbDecks() - count($user->getDecks());
        $slots_required = count($meteor_data);
        if ($slots_required > $slots_left) {
            $this->get('session')
                ->getFlashBag()
                ->set('error',
                    "You don't have enough available deck slots to import the $slots_required decks from Meteor (only $slots_left slots left). You must either delete some decks here or on Meteor Decks.");
            return $this->redirect($this->generateUrl('decks_list'));
        }
        
        foreach ($meteor_data as $meteor_deck) {
            // add a tag for side and faction of deck
            $identity_code = $glossary[$meteor_deck['identity']];
            /* @var $identity \Netrunnerdb\CardsBundle\Entity\Card */
            $identity = $em->getRepository('NetrunnerdbCardsBundle:Card')->findOneBy(array('code' => $identity_code));
            if(!$identity) continue;
            $faction_code = $identity->getFaction()->getCode();
            $side_code = strtolower($identity->getSide()->getName());
            $tags = array($faction_code, $side_code);

            $content = array(
                    $identity_code => 1
            );
            foreach ($meteor_deck['entries'] as $entry => $qty) {
                if (! isset($glossary[$entry])) {
                    $this->get('session')
                        ->getFlashBag()
                        ->set('error', "Error importing a deck. The name \"$entry\" doesn't match any known card. Please contact the administrator.");
                    return $this->redirect($this->generateUrl('decks_list'));
                }
                $content[$glossary[$entry]] = $qty;
            }
            
            /* @var $deck Deck */
            $deck = new Deck();
            $this->get('decks')->save($this->getUser(), $deck, null, $meteor_deck['name'], "", $tags, $content);
        }
        
        $this->get('session')
            ->getFlashBag()
            ->set('notice', "Successfully imported $slots_required decks from Meteor Decks.");
        
        return $this->redirect($this->generateUrl('decks_list'));
    
    }

    public function textexportAction ($deck_id)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        /* @var $deck \Netrunnerdb\BuilderBundle\Entity\Deck */
        $deck = $em->getRepository('NetrunnerdbBuilderBundle:Deck')->find($deck_id);
        if (! $this->getUser() || $this->getUser()->getId() != $deck->getUser()->getId())
            throw new UnauthorizedHttpException("You don't have access to this deck.");
            
            /* @var $judge \Netrunnerdb\SocialBundle\Services\Judge */
        $judge = $this->get('judge');
        $classement = $judge->classe($deck->getCards(), $deck->getIdentity());
        
        $lines = array();
        $types = array(
                "Event",
                "Hardware",
                "Resource",
                "Icebreaker",
                "Program",
                "Agenda",
                "Asset",
                "Upgrade",
                "Operation",
                "Barrier",
                "Code Gate",
                "Sentry",
                "ICE"
        );
        
        $lines[] = $deck->getIdentity()->getTitle() . " (" . $deck->getIdentity()
            ->getPack()
            ->getName() . ")";
        foreach ($types as $type) {
            if (isset($classement[$type]) && $classement[$type]['qty']) {
                $lines[] = "";
                $lines[] = $type . " (" . $classement[$type]['qty'] . ")";
                foreach ($classement[$type]['slots'] as $slot) {
                    $inf = "";
                    for ($i = 0; $i < $slot['influence']; $i ++) {
                        if ($i % 5 == 0)
                            $inf .= " ";
                        $inf .= "•";
                    }
                    $lines[] = $slot['qty'] . "x " . $slot['card']->getTitle() . " (" . $slot['card']->getPack()->getName() . ") " . $inf;
                }
            }
        }
        $lines[] = "";
        $lines[] = $deck->getInfluenceSpent() . " influence spent (maximum " . (is_numeric($deck->getIdentity()->getInfluenceLimit()) ? $deck->getIdentity()->getInfluenceLimit() : "infinite") . ")";
        if ($deck->getSide()->getName() == "Corp") {
            $minAgendaPoints = floor($deck->getDeckSize() / 5) * 2 + 2;
            $lines[] = $deck->getAgendaPoints() . " agenda points (between " . $minAgendaPoints . " and " . ($minAgendaPoints + 1) . ")";
        }
        $lines[] = $deck->getDeckSize() . " cards (min " . $deck->getIdentity()->getMinimumDeckSize() . ")";
        $lines[] = "Cards up to " . $deck->getLastPack()->getName();
        $content = implode("\r\n", $lines);
        
        $name = mb_strtolower($deck->getName());
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name);
        $name = preg_replace('/--+/', '-', $name);
        
        $response = new Response();
        
        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set('Content-Disposition', 'attachment;filename=' . $name . ".txt");
        
        $response->setContent($content);
        return $response;
    
    }

    public function octgnexportAction ($deck_id)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        /* @var $deck \Netrunnerdb\BuilderBundle\Entity\Deck */
        $deck = $em->getRepository('NetrunnerdbBuilderBundle:Deck')->find($deck_id);
        if (! $this->getUser() || $this->getUser()->getId() != $deck->getUser()->getId())
            throw new UnauthorizedHttpException("You don't have access to this deck.");
        
        $rd = array();
        $identity = null;
        /** @var $slot Deckslot */
        foreach ($deck->getSlots() as $slot) {
            if ($slot->getCard()
                ->getType()
                ->getName() == "Identity") {
                $identity = array(
                        "index" => $slot->getCard()->getCode(),
                        "name" => $slot->getCard()->getTitle()
                );
            } else {
                $rd[] = array(
                        "index" => $slot->getCard()->getCode(),
                        "name" => $slot->getCard()->getTitle(),
                        "qty" => $slot->getQuantity()
                );
            }
        }
        $name = mb_strtolower($deck->getName());
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name);
        $name = preg_replace('/--+/', '-', $name);
        if (empty($identity)) {
            return new Response('no identity found');
        }
        return $this->octgnexport("$name.o8d", $identity, $rd, $deck->getDescription());
    
    }

    public function octgnexport ($filename, $identity, $rd, $description)
    {

        $content = $this->renderView('NetrunnerdbBuilderBundle::octgn.xml.twig',
                array(
                        "identity" => $identity,
                        "rd" => $rd,
                        "description" => strip_tags($description)
                ));
        
        $response = new Response();
        
        $response->headers->set('Content-Type', 'application/octgn');
        $response->headers->set('Content-Disposition', 'attachment;filename=' . $filename);
        
        $response->setContent($content);
        return $response;
    
    }

    public function saveAction (Request $request)
    {

        $user = $this->getUser();
        if (count($user->getDecks()) > $user->getMaxNbDecks())
            return new Response('You have reached the maximum number of decks allowed. Delete some decks or increase your reputation.');
        
        $is_copy = (boolean) filter_var($request->get('copy'), FILTER_SANITIZE_NUMBER_INT);
        $name = filter_var($request->get('name'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        $id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);
        $decklist_id = filter_var($request->get('decklist_id'), FILTER_SANITIZE_NUMBER_INT);
        $description = filter_var($request->get('description'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        $tags = filter_var($request->get('tags'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        $content = (array) json_decode($request->get('content'));
        if (! count($content))
            return new Response('Cannot import empty deck');
        
        if ($is_copy && $id) {
            $id = null;
        }
        
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        if ($id) {
            $deck = $em->getRepository('NetrunnerdbBuilderBundle:Deck')->find($id);
            if ($user->getId() != $deck->getUser()->getId())
                throw new UnauthorizedHttpException("You don't have access to this deck.");
            foreach ($deck->getSlots() as $slot) {
                $deck->removeSlot($slot);
                $em->remove($slot);
            }
        } else {
            $deck = new Deck();
        }
        
        $this->get('decks')->save($this->getUser(), $deck, $decklist_id, $name, $description, $tags, $content);

        return $this->redirect($this->generateUrl('decks_list'));
    
    }

    public function deleteAction (Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        $deck_id = filter_var($request->get('deck_id'), FILTER_SANITIZE_NUMBER_INT);
        $deck = $em->getRepository('NetrunnerdbBuilderBundle:Deck')->find($deck_id);
        if (! $deck)
            return $this->redirect($this->generateUrl('decks_list'));
        if ($this->getUser()->getId() != $deck->getUser()->getId())
            throw new UnauthorizedHttpException("You don't have access to this deck.");
        
        foreach ($deck->getChildren() as $decklist) {
            $decklist->setParent(null);
        }
        $em->remove($deck);
        $em->flush();
        
        $this->get('session')
            ->getFlashBag()
            ->set('notice', "Deck deleted.");
        
        return $this->redirect($this->generateUrl('decks_list'));
    
    }

    public function deleteListAction (Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        $list_id = explode('-', $request->get('ids'));

        foreach($list_id as $id)
        {
            /* @var $deck Deck */
            $deck = $em->getRepository('NetrunnerdbBuilderBundle:Deck')->find($id);
            if(!$deck) continue;
            if ($this->getUser()->getId() != $deck->getUser()->getId()) continue;
            
            foreach ($deck->getChildren() as $decklist) {
                $decklist->setParent(null);
            }
            $em->remove($deck);
        }
        $em->flush();
        
        $this->get('session')
            ->getFlashBag()
            ->set('notice', "Decks deleted.");
        
        return $this->redirect($this->generateUrl('decks_list'));
    }

    public function editAction ($deck_id)
    {

        $dbh = $this->get('doctrine')->getConnection();
        $rows = $dbh->executeQuery("SELECT
				d.id,
				d.name,
				d.description,
                d.tags,
				s.name side_name
				from deck d
				left join side s on d.side_id=s.id
				where d.id=?
				", array(
                $deck_id
        ))->fetchAll();
        
        $deck = $rows[0];
        $deck['side_name'] = mb_strtolower($deck['side_name']);
        
        $rows = $dbh->executeQuery("SELECT
				c.code,
				s.quantity
				from deckslot s
				join card c on s.card_id=c.id
				where s.deck_id=?", array(
                $deck_id
        ))->fetchAll();
        
        $cards = array();
        foreach ($rows as $row) {
            $cards[$row['code']] = $row['quantity'];
        }
        $deck['slots'] = $cards;
        
        $published_decklists = $dbh->executeQuery(
                "SELECT
					d.id,
					d.name,
					d.prettyname,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					from decklist d
					where d.parent_deck_id=?
					order by d.creation asc", array(
                        $deck_id
                ))->fetchAll();
        
        return $this->render('NetrunnerdbBuilderBundle:Builder:deck.html.twig',
                array(
                        'pagetitle' => "Deckbuilder",
                        'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
                        'deck' => $deck,
                        'published_decklists' => $published_decklists
                ));
    
    }

    public function viewAction ($deck_id)
    {

        $dbh = $this->get('doctrine')->getConnection();
        $rows = $dbh->executeQuery("SELECT
				d.id,
				d.name,
				d.description,
                d.problem,
				s.name side_name,
				c.code identity_code,
				f.code faction_code
                from deck d
				join side s on d.side_id=s.id
				join card c on d.identity_id=c.id
				join faction f on c.faction_id=f.id
                where d.id=?
				", array(
                $deck_id
        ))->fetchAll();
        
        $deck = $rows[0];
        $deck['side_name'] = mb_strtolower($deck['side_name']);
        
        $rows = $dbh->executeQuery("SELECT
				c.code,
				s.quantity
				from deckslot s
				join card c on s.card_id=c.id
				where s.deck_id=?", array(
                $deck_id
        ))->fetchAll();
        
        $cards = array();
        foreach ($rows as $row) {
            $cards[$row['code']] = $row['quantity'];
        }
        $deck['slots'] = $cards;
        
        $published_decklists = $dbh->executeQuery(
                "SELECT
					d.id,
					d.name,
					d.prettyname,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					from decklist d
					where d.parent_deck_id=?
					order by d.creation asc", array(
                        $deck_id
                ))->fetchAll();

		$problem = $deck['problem'];
		$deck['message'] = isset($problem) ? $this->get('judge')->problem($problem) : '';
		
        return $this->render('NetrunnerdbBuilderBundle:Builder:deckview.html.twig',
                array(
                        'pagetitle' => "Deckbuilder",
                        'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
                        'deck' => $deck,
                        'published_decklists' => $published_decklists
                ));
    
    }

    public function listAction ()
    {
        /* @var $user \Netrunnerdb\UserBundle\Entity\User */
        $user = $this->getUser();
        
        return $this->render('NetrunnerdbBuilderBundle:Builder:decks.html.twig',
                array(
                        'pagetitle' => "My Decks",
                        'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
                        'decks' => $this->get('decks')
                            ->getByUser($user),
                        'nbmax' => $user->getMaxNbDecks()
                ));
    
    }

    public function copyAction ($decklist_id)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        /* @var $decklist \Netrunnerdb\BuilderBundle\Entity\Decklist */
        $decklist = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->find($decklist_id);
        
        $content = array();
        foreach ($decklist->getSlots() as $slot) {
            $content[$slot->getCard()->getCode()] = $slot->getQuantity();
        }
        return $this->forward('NetrunnerdbBuilderBundle:Builder:save',
                array(
                        'name' => $decklist->getName(),
                        'content' => json_encode($content),
                        'decklist_id' => $decklist_id
                ));
    
    }

    public function duplicateAction ($deck_id)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
    
        /* @var $deck \Netrunnerdb\BuilderBundle\Entity\Deck */
        $deck = $em->getRepository('NetrunnerdbBuilderBundle:Deck')->find($deck_id);
    
        $content = array();
        foreach ($deck->getSlots() as $slot) {
            $content[$slot->getCard()->getCode()] = $slot->getQuantity();
        }
        return $this->forward('NetrunnerdbBuilderBundle:Builder:save',
                array(
                        'name' => $deck->getName().' (copy)',
                        'content' => json_encode($content),
                        'deck_id' => $deck->getParent() ? $deck->getParent()->getId() : null
                ));
    
    }
}
