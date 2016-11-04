<?php
namespace Librecores\ProjectRepoBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Doctrine\Bundle\DoctrineBundle\Registry;

/**
 * Validate a user or organization name
 *
 * Validation encompasses both length rules of the name, as well as enforcing
 * the uniqueness of it. Some of the validation rules in this class might
 * duplicate other validation rules (i.e. the length validation), but we add
 * them here anyways to have *one* place which ensures that no user or org name
 * with invalid format can be chosen.
 */
class UserOrgNameValidator extends ConstraintValidator
{
    /**
     * Minimum length of the user or org name
     *
     * @var integer
     */
    const LENGTH_MIN = 4;

    /**
     * Maximum length of the user or org name
     *
     * @var integer
     */
    const LENGTH_MAX = 15;
    /**
     * Names reserved for internal use, mostly to avoid clashes where
     * a name is equal to a route.
     * To be on the safe side, also add names which would be considered
     * invalid by the length rules above (variables might change).
     *
     * @var string[]
     */
    const RESERVED_NAMES = [ 'org', 'orgs', 'planet', 'project', 'projects',
                             'search', 'static', 'unassigned', 'user' ];

    /**
     * Regular expression checking for valid characters in an user or org name
     *
     * The name is first converted to lowercase before passing to this regex,
     * and the length of the name is already checked.
     * When changing this regex also change the validation message inside the
     * UserOrgName class.
     *
     * @var string
     */
    const VALID_NAME_REGEX = '/^[a-z][a-z0-9-]+$/';

    /** @var Doctrine\Bundle\DoctrineBundle\Registry */
    private $orm;

    public function __construct(Registry $doctrine)
    {
        $this->orm = $doctrine;
    }

    public function validate($value, Constraint $constraint)
    {
        $value = strtolower($value);

        if (strlen($value) < self::LENGTH_MIN) {
            $this->context->buildViolation($constraint->messageTooShort)
                ->setParameter('%string%', $value)
                ->setParameter('%minlength%', self::LENGTH_MIN)
                ->addViolation();
        }

        if (strlen($value) > self::LENGTH_MAX) {
            $this->context
                ->buildViolation($constraint->messageTooLong)
                ->setParameter('%string%', $value)
                ->setParameter('%maxlength%', self::LENGTH_MAX)
                ->addViolation();
        }

        if (in_array($value, self::RESERVED_NAMES)) {
            $this->context
                ->buildViolation($constraint->messageReservedName)
                ->setParameter('%string%', $value)
                ->addViolation();
        }

        if (!preg_match(self::VALID_NAME_REGEX, $value)) {
            $this->context
                ->buildViolation($constraint->messageInvalidCharacters)
                ->setParameter('%string%', $value)
                ->addViolation();
        }

        if ($this->userOrOrgNameExists($value)) {
            $this->context
                ->buildViolation($constraint->messageUniqueName)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }

    /**
     * Check if a username or organization name already exists on LibreCores
     *
     * @param string $name user or org name
     */
    private function userOrOrgNameExists($name)
    {
        $name = strtolower($name);
        $em = $this->orm->getManager();

        $q =  'SELECT COUNT(u.id) FROM LibrecoresProjectRepoBundle:User u '.
              'WHERE u.usernameCanonical = :name';
        $cnt_user = $em->createQuery($q)
            ->setParameter('name', $name)
            ->getSingleScalarResult();
        if ($cnt_user != 0) {
            return true;
        }

        $q =  'SELECT COUNT(o.id) '.
              'FROM LibrecoresProjectRepoBundle:Organization o '.
              'WHERE LOWER(o.name) = :name';
        $cnt_org = $em->createQuery($q)
            ->setParameter('name', $name)
            ->getSingleScalarResult();
        if ($cnt_org != 0) {
            return true;
        }
    }
}
