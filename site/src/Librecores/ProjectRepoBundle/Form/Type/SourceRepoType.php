<?php

namespace Librecores\ProjectRepoBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form to edit the details of a source code repository.
 *
 * @todo   This class is currently hardcoded to support only GitSourceRepo and
 *   no other sibling class. Change if other source repo types should be
 *   supported.
 *
 * @author Philipp Wagner <mail@philipp-wagner.com>
 */
class SourceRepoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('url', UrlType::class, array('label' => 'Git clone URL', 'required' => true))
            ->add('webViewUrl', UrlType::class, array('label' => 'Repository web view URL', 'required' => false));
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            array(
                'data_class' => 'Librecores\ProjectRepoBundle\Entity\GitSourceRepo',
            )
        );
    }
}
