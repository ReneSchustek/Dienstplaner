<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Department;
use App\Entity\User;
use App\Service\PlanerScope;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class PlanerScopeTest extends TestCase
{
    private PlanerScope $scope;

    protected function setUp(): void
    {
        $this->scope = new PlanerScope();
    }

    // -------------------------------------------------------------------------
    // isActive
    // -------------------------------------------------------------------------

    public function testIsActiveForPlaner(): void
    {
        $user = $this->userWithRole('ROLE_PLANER');
        $this->assertTrue($this->scope->isActive($user));
    }

    public function testIsNotActiveForAssemblyAdmin(): void
    {
        $user = $this->userWithRole('ROLE_ASSEMBLY_ADMIN');
        $this->assertFalse($this->scope->isActive($user));
    }

    public function testIsNotActiveForAdmin(): void
    {
        $user = $this->userWithRole('ROLE_ADMIN');
        $this->assertFalse($this->scope->isActive($user));
    }

    public function testIsNotActiveForUser(): void
    {
        $user = $this->userWithRole('ROLE_USER');
        $this->assertFalse($this->scope->isActive($user));
    }

    // -------------------------------------------------------------------------
    // getDepartmentIds
    // -------------------------------------------------------------------------

    public function testGetDepartmentIdsReturnsIds(): void
    {
        $dept1 = $this->departmentWithId(10);
        $dept2 = $this->departmentWithId(20);

        $user = $this->userWithRole('ROLE_PLANER');
        $user->addDepartment($dept1);
        $user->addDepartment($dept2);

        $ids = $this->scope->getDepartmentIds($user);

        $this->assertSame([10, 20], $ids);
    }

    public function testGetDepartmentIdsReturnsEmptyForNoDepartments(): void
    {
        $user = $this->userWithRole('ROLE_PLANER');
        $this->assertSame([], $this->scope->getDepartmentIds($user));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function userWithRole(string $role): User
    {
        $user = new User();
        $user->setRole($role);
        $user->setEmail('test@example.com');
        return $user;
    }

    private function departmentWithId(int $id): Department
    {
        $dept = new Department();
        // Reflection to set private id (no factory in entity)
        $ref = new \ReflectionProperty(Department::class, 'id');
        $ref->setValue($dept, $id);
        return $dept;
    }
}
