<?php

namespace PHPSTORM_META;

override(\Psr\Container\ContainerInterface::get(0), map(['' => '@']));
override(\League\Container\ReflectionContainer::get(0), map(['' => '@']));
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
