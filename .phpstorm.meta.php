<?php

namespace PHPSTORM_META;

override(\App\Libs\Container::get(0), map(['' => '@']));
override(\App\Libs\Extends\PSRContainer::get(0), map(['' => '@']));
override(\Psr\Container\ContainerInterface::get(0), map(['' => '@']));
override(\League\Container\ReflectionContainer::get(0), map(['' => '@']));
override(\App\Libs\Container::getNew(0), map(['' => '@']));
override(\App\Libs\Extends\PSRContainer::getNew(0), map(['' => '@']));
override(
    \App\Command::getHelper(0),
    map([
        'question' => QuestionHelper::class,
    ])
);
override(
    \Symfony\Component\Console\Command\Command::getHelper(0),
    map([
        'question' => \Symfony\Component\Console\Helper\SymfonyQuestionHelper::class,
    ])
);
