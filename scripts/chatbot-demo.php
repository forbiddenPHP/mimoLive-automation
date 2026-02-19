<?php
chatbot_demo:

    $base = 'http://localhost:8888/';

    file_get_contents($base . '?f=functions/gift-sub&toStack&username=LuckyCharm&giftcount=10&tier=Tier%201');
    file_get_contents($base . '?f=functions/new-sub&toStack&username=FreshFish99&tier=Tier%203');
    file_get_contents($base . '?f=functions/cheer-alert&toStack&username=DiamondHands&bits=2500');
    file_get_contents($base . '?f=functions/hype-chat&toStack&username=BigSpender&amount=50&currency=USD&level=FOUR');
    file_get_contents($base . '?f=functions/raid-alert&toStack&username=ZephyrStorm&viewers=750&userimageurl=https%3A%2F%2Fplacecats.com%2Flouie%2F300%2F300');

    // Start stack processing
    $doc = 'hosts/master/documents/' . array_keys(namedAPI_get('hosts/master/documents'))[0] . '/';
    setLive($doc.'layers/Scripts/variants/listen-to-chatbot');
