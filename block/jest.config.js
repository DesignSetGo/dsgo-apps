module.exports = {
  testEnvironment: 'jsdom',
  preset: 'ts-jest',
  testMatch: ['<rootDir>/tests/**/*.test.{ts,tsx}'],
  setupFilesAfterEnv: ['@testing-library/jest-dom'],
  transform: {
    '^.+\\.(ts|tsx)$': ['ts-jest', { tsconfig: { jsx: 'react-jsx' } }],
  },
  moduleNameMapper: {
    '\\.(scss|css)$': '<rootDir>/tests/style-mock.js',
  },
};
