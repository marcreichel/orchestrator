<?php

it('renders the three deferred sections', function () {
    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire('issues')
        ->assertSeeLivewire('prs')
        ->assertSeeLivewire('workspaces');
});
